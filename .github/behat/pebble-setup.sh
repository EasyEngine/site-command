#!/usr/bin/env bash
# Redirects EasyEngine's hardcoded Let's Encrypt endpoint to a local Pebble on a throwaway CI runner, so the @le Behat
# scenarios run without public DNS or real certificates. Test-only: no product code changes.
#
#   pebble-setup.sh setup      start Pebble + DNS, redirect and trust, block real LE, then run `verify`
#   pebble-setup.sh block      lanes without Pebble: point the LE host at a blackhole and block real LE
#   pebble-setup.sh verify     prove EE's HTTP stack reaches Pebble (and cannot reach real LE)
#   pebble-setup.sh reach      after the EE proxy is up: prove Pebble's VA can fetch http://<target>/ (HTTP-01 path)
#   pebble-setup.sh logs       print the Pebble, proxy and DNS logs
#   pebble-setup.sh teardown   undo everything setup or block did
#
# Needs root, docker, openssl, curl, php, iptables and systemd-resolved (GitHub's ubuntu runners have all of them).
# Pebble's root is trusted system-wide while it runs, so never leave it set up on a machine used for anything else.
set -euo pipefail

PEBBLE_DIR=${PEBBLE_DIR:-/tmp/ee-pebble}
# 2.10+ also offers the token-less draft dns-persist-01 challenge, which EE's acmephp 1.3 client can't parse.
PEBBLE_VERSION=${PEBBLE_VERSION:-2.9.0}
PEBBLE_NET=${PEBBLE_NET:-ee-pebble}
PEBBLE_BRIDGE=${PEBBLE_BRIDGE:-ee-pebble0}
PEBBLE_SUBNET=${PEBBLE_SUBNET:-172.30.0.0/24}
PEBBLE_GATEWAY=${PEBBLE_GATEWAY:-172.30.0.1}
# EE's host resolves to the front proxy: Pebble serves its directory at /dir, EE requests /directory.
PEBBLE_IP=${PEBBLE_IP:-172.30.0.10}
PEBBLE_ACME_IP=${PEBBLE_ACME_IP:-172.30.0.12}
PEBBLE_PROXY_IMAGE=${PEBBLE_PROXY_IMAGE:-nginx:1.29-alpine}
PEBBLE_DNS_IP=${PEBBLE_DNS_IP:-172.30.0.11}
PEBBLE_DNS_IMAGE=${PEBBLE_DNS_IMAGE:-alpine:3.22}
# Every name under this domain resolves to PEBBLE_TARGET_IP, on the runner and for Pebble's VA.
PEBBLE_DOMAIN=${PEBBLE_DOMAIN:-ee4-test.rt.gw}
# The gateway is a host address, so both the runner and the VA reach nginx-proxy's published :80 through it.
PEBBLE_TARGET_IP=${PEBBLE_TARGET_IP:-$PEBBLE_GATEWAY}
ACME_HOST=acme-v02.api.letsencrypt.org
TOS_MARKER='Do%20what%20thou%20wilt'
# TEST-NET-1: never routed, and rejected locally so a request fails at once instead of timing out.
BLACKHOLE_IP=${BLACKHOLE_IP:-192.0.2.1}
PUBLIC_PROBE=api.wordpress.org
MARK='# ee-pebble'
RESOLVED_DROPIN=/etc/systemd/resolved.conf.d/ee-pebble.conf
CA_LINK=/usr/local/share/ca-certificates/ee-pebble-ca.crt
ROOT_LINK=/usr/local/share/ca-certificates/ee-pebble-root.crt
STATE="$PEBBLE_DIR/state"

log() { printf '[pebble] %s\n' "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

need_root() { [[ $EUID -eq 0 ]] || die 'run as root'; }

# Records an undo command (one per line) for teardown.
remember() { printf '%s\n' "$*" >>"$STATE"; }

public_ips() {
	# $1 = ahostsv4|ahostsv6, $2 = name; resolved before any override is installed.
	getent "$1" "$2" 2>/dev/null | awk '{print $1}' | sort -u || true
}

block_ip() {
	local ip=$1 tool=iptables
	[[ $ip == *:* ]] && tool=ip6tables
	command -v "$tool" >/dev/null || return 0
	"$tool" -I OUTPUT -d "$ip" -j REJECT
	remember "$tool -D OUTPUT -d $ip -j REJECT"
	if "$tool" -n -L DOCKER-USER >/dev/null 2>&1; then
		"$tool" -I DOCKER-USER -d "$ip" -j REJECT
		remember "$tool -D DOCKER-USER -d $ip -j REJECT"
	fi
	log "blocked egress to $ip"
}

make_certs() {
	local d=$PEBBLE_DIR
	openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=EE Behat Pebble listener CA' \
		-addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
		-keyout "$d/ca.key" -out "$d/ca.pem" 2>/dev/null
	openssl req -newkey rsa:2048 -nodes -subj "/CN=$ACME_HOST" -keyout "$d/key.pem" -out "$d/cert.csr" 2>/dev/null
	printf 'subjectAltName=DNS:%s,IP:%s\nextendedKeyUsage=serverAuth\nbasicConstraints=critical,CA:FALSE\n' \
		"$ACME_HOST" "$PEBBLE_IP,IP:$PEBBLE_ACME_IP" >"$d/cert.ext"
	openssl x509 -req -in "$d/cert.csr" -CA "$d/ca.pem" -CAkey "$d/ca.key" -CAcreateserial -days 2 \
		-extfile "$d/cert.ext" -out "$d/cert.pem" 2>/dev/null
	rm -f "$d/ca.key" "$d/cert.csr" "$d/ca.srl"
	# Pebble runs as root in a scratch image; the key only has to be readable by it.
	chmod 644 "$d/key.pem"
}

write_config() {
	# The blocklist mirrors LE refusing reserved TLDs.
	cat >"$PEBBLE_DIR/pebble-config.json" <<-EOF
		{
		  "pebble": {
		    "listenAddress": "0.0.0.0:14000",
		    "managementListenAddress": "0.0.0.0:15000",
		    "certificate": "/pebble/cert.pem",
		    "privateKey": "/pebble/key.pem",
		    "httpPort": 80,
		    "tlsPort": 443,
		    "ocspResponderURL": "",
		    "externalAccountBindingRequired": false,
		    "domainBlocklist": ["invalid", "test", "example", "localhost", "local"],
		    "retryAfter": { "authz": 3, "order": 5 },
		    "keyAlgorithm": "ecdsa",
		    "profiles": {
		      "default": { "description": "LE classic: 90 days, first name as CN", "validityPeriod": 7776000, "promoteCommonName": true }
		    }
		  }
		}
	EOF
}

write_front_config() {
	# Pebble builds its URLs from Host and X-Forwarded-Proto, so they come back as https://$ACME_HOST/...
	cat >"$PEBBLE_DIR/front.conf" <<-EOF
		server {
		    listen 443 ssl;
		    ssl_certificate /pebble/cert.pem;
		    ssl_certificate_key /pebble/key.pem;
		    proxy_set_header Host \$host;
		    proxy_set_header X-Forwarded-Proto https;
		    proxy_redirect off;
		    proxy_ssl_server_name on;
		    proxy_ssl_name $ACME_HOST;
		    proxy_ssl_verify on;
		    proxy_ssl_trusted_certificate /pebble/ca.pem;
		    location = /directory { proxy_pass https://$PEBBLE_ACME_IP:14000/dir; }
		    # Boulder puts a blank line between chain PEMs and acmephp splits on it; Pebble puts none.
		    location /certZ/ {
		        proxy_pass https://$PEBBLE_ACME_IP:14000;
		        # A location-level proxy_set_header drops the inherited ones; Pebble checks the kid against Host.
		        proxy_set_header Host \$host;
		        proxy_set_header X-Forwarded-Proto https;
		        proxy_set_header Accept-Encoding "";
		        sub_filter_types application/pem-certificate-chain;
		        sub_filter_once off;
		        sub_filter "-----END CERTIFICATE-----
		-----BEGIN CERTIFICATE-----" "-----END CERTIFICATE-----

		-----BEGIN CERTIFICATE-----";
		    }
		    location / { proxy_pass https://$PEBBLE_ACME_IP:14000; }
		}
	EOF
}

wait_for() {
	# $1 = description, rest = command; up to 60 s.
	local what=$1 i
	shift
	for ((i = 0; i < 60; i++)); do
		"$@" >/dev/null 2>&1 && return 0
		sleep 1
	done
	die "timed out waiting for $what"
}

start_containers() {
	docker network create --driver bridge --subnet "$PEBBLE_SUBNET" --gateway "$PEBBLE_GATEWAY" \
		-o "com.docker.network.bridge.name=$PEBBLE_BRIDGE" "$PEBBLE_NET" >/dev/null
	remember "docker network rm $PEBBLE_NET"
	# dnsmasq, not challtestsrv: it answers only the test domain and forwards everything else, because resolved's
	# global DNS also ends up in the containers' resolv.conf (challtestsrv answers every name, even wordpress.org).
	local upstream
	upstream=$(awk '$1 == "nameserver" && $2 !~ /^127\./ {print $2; exit}' /run/systemd/resolve/resolv.conf 2>/dev/null)
	[[ -n $upstream ]] || die 'no upstream DNS server found in /run/systemd/resolve/resolv.conf'
	docker run -d --name ee-pebble-dns --network "$PEBBLE_NET" --ip "$PEBBLE_DNS_IP" "$PEBBLE_DNS_IMAGE" \
		sh -c "apk add -q --no-cache dnsmasq && exec dnsmasq -k --no-hosts --no-resolv --log-queries --log-facility=- \
			--server=$upstream --address=/$PEBBLE_DOMAIN/$PEBBLE_TARGET_IP" >/dev/null
	remember 'docker rm -f ee-pebble-dns'
	docker run -d --name ee-pebble --network "$PEBBLE_NET" --ip "$PEBBLE_ACME_IP" \
		-e PEBBLE_VA_NOSLEEP=1 -e PEBBLE_WFE_NONCEREJECT=0 -e PEBBLE_AUTHZREUSE=100 \
		-v "$PEBBLE_DIR:/pebble:ro" "ghcr.io/letsencrypt/pebble:$PEBBLE_VERSION" \
		-config /pebble/pebble-config.json -dnsserver "$PEBBLE_DNS_IP:53" >/dev/null
	remember 'docker rm -f ee-pebble'
	docker run -d --name ee-pebble-front --network "$PEBBLE_NET" --ip "$PEBBLE_IP" \
		-v "$PEBBLE_DIR:/pebble:ro" -v "$PEBBLE_DIR/front.conf:/etc/nginx/conf.d/default.conf:ro" \
		"$PEBBLE_PROXY_IMAGE" >/dev/null
	remember 'docker rm -f ee-pebble-front'
}

allow_va_forwarding() {
	# Docker's inter-network isolation may drop the VA's DNATed request to nginx-proxy's bridge; DOCKER-USER runs first.
	iptables -n -L DOCKER-USER >/dev/null 2>&1 || return 0
	iptables -I DOCKER-USER -o "$PEBBLE_BRIDGE" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
	remember "iptables -D DOCKER-USER -o $PEBBLE_BRIDGE -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT"
	iptables -I DOCKER-USER -i "$PEBBLE_BRIDGE" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
	remember "iptables -D DOCKER-USER -i $PEBBLE_BRIDGE -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT"
	iptables -I DOCKER-USER -i "$PEBBLE_BRIDGE" -p tcp --dport 80 -j ACCEPT
	remember "iptables -D DOCKER-USER -i $PEBBLE_BRIDGE -p tcp --dport 80 -j ACCEPT"
	# Site containers get the resolver from resolved's resolv.conf, which now lists the Pebble DNS.
	local proto
	for proto in udp tcp; do
		iptables -I DOCKER-USER -o "$PEBBLE_BRIDGE" -d "$PEBBLE_DNS_IP" -p "$proto" --dport 53 -j ACCEPT
		remember "iptables -D DOCKER-USER -o $PEBBLE_BRIDGE -d $PEBBLE_DNS_IP -p $proto --dport 53 -j ACCEPT"
	done
}

redirect_acme_host() {
	cp /etc/hosts "$PEBBLE_DIR/hosts.bak"
	printf '%s\t%s %s\n' "$PEBBLE_IP" "$ACME_HOST" "$MARK" >>/etc/hosts
	remember "sed -i '/ $MARK\$/d' /etc/hosts"
}

route_test_domain() {
	systemctl is-active --quiet systemd-resolved || die 'systemd-resolved is not active; cannot route *.'"$PEBBLE_DOMAIN"
	mkdir -p "$(dirname "$RESOLVED_DROPIN")"
	printf '[Resolve]\nDNS=%s\nDomains=~%s\n' "$PEBBLE_DNS_IP" "$PEBBLE_DOMAIN" >"$RESOLVED_DROPIN"
	remember "rm -f $RESOLVED_DROPIN; systemctl restart systemd-resolved"
	systemctl restart systemd-resolved
	resolvectl flush-caches 2>/dev/null || true
}

php_cafiles() {
	# CA files PHP is configured to use instead of the system bundle, if any.
	php <<-'PHP' | sort -u
		<?php foreach (['curl.cainfo', 'openssl.cafile'] as $k) { $v = ini_get($k); if ($v) { echo $v, "\n"; } }
	PHP
}

trust_cas() {
	local f pem
	cp "$PEBBLE_DIR/ca.pem" "$CA_LINK"
	cp "$PEBBLE_DIR/pebble-root.pem" "$ROOT_LINK"
	remember "rm -f $CA_LINK $ROOT_LINK; update-ca-certificates --fresh >/dev/null"
	update-ca-certificates >/dev/null
	# Guzzle (verify=true) and PHP streams fall back to these when set, so they must trust the CAs too.
	while IFS= read -r f; do
		[[ -f $f ]] || continue
		[[ $(readlink -f "$f") == "$(readlink -f /etc/ssl/certs/ca-certificates.crt)" ]] && continue
		cp "$f" "$f.ee-pebble.bak"
		remember "mv -f '$f.ee-pebble.bak' '$f'"
		for pem in "$PEBBLE_DIR/ca.pem" "$PEBBLE_DIR/pebble-root.pem"; do cat "$pem" >>"$f"; done
		log "appended the CAs to PHP's $f"
	done < <(php_cafiles)
}

write_env() {
	local issuer
	issuer=$(openssl x509 -in "$PEBBLE_DIR/pebble-intermediate.pem" -noout -subject -nameopt multiline |
		sed -n 's/^ *commonName *= *//p')
	[[ -n $issuer ]] || die 'could not read the Pebble intermediate CN'
	printf 'EE_BEHAT_LE_ISSUER=%s\nEE_BEHAT_LE_DNS_SERVER=127.0.0.53\nEE_BEHAT_PEBBLE_ROOT=%s\n' \
		"$issuer" "$PEBBLE_DIR/pebble-root.pem" >"$PEBBLE_DIR/env"
	if [[ -n ${GITHUB_ENV:-} ]]; then
		cat "$PEBBLE_DIR/env" >>"$GITHUB_ENV"
	fi
	log "issuer for the scenarios: $issuer"
}

cmd_setup() {
	need_root
	[[ ! -e $STATE ]] || die "$STATE exists; run teardown first"
	mkdir -p "$PEBBLE_DIR"
	: >"$STATE"

	local ip
	# Resolve before the overrides so the real addresses get blocked.
	for ip in $(public_ips ahostsv4 "$ACME_HOST") $(public_ips ahostsv6 "$ACME_HOST") \
		$(public_ips ahostsv4 "$PEBBLE_DOMAIN") $(public_ips ahostsv6 "$PEBBLE_DOMAIN"); do
		block_ip "$ip"
	done

	make_certs
	write_config
	write_front_config
	start_containers
	allow_va_forwarding
	redirect_acme_host

	wait_for 'the Pebble directory' curl -fsS --cacert "$PEBBLE_DIR/ca.pem" "https://$ACME_HOST/directory"
	curl -fsS --cacert "$PEBBLE_DIR/ca.pem" "https://$PEBBLE_ACME_IP:15000/roots/0" -o "$PEBBLE_DIR/pebble-root.pem"
	curl -fsS --cacert "$PEBBLE_DIR/ca.pem" "https://$PEBBLE_ACME_IP:15000/intermediates/0" -o "$PEBBLE_DIR/pebble-intermediate.pem"

	trust_cas
	wait_for 'the Pebble DNS' docker exec ee-pebble-dns pidof dnsmasq
	route_test_domain
	write_env
	cmd_verify
	log 'setup done'
}

cmd_verify() {
	local probe resolved ip body
	resolved=$(getent ahostsv4 "$ACME_HOST" | awk 'NR==1{print $1}')
	[[ $resolved == "$PEBBLE_IP" ]] || die "$ACME_HOST resolves to '$resolved', not Pebble ($PEBBLE_IP)"

	body=$(curl -fsS "https://$ACME_HOST/directory") || die 'curl with the system trust store cannot reach Pebble'
	[[ $body == *"$TOS_MARKER"* ]] || die "the directory at $ACME_HOST is not Pebble's: $body"
	[[ $body == *"https://$ACME_HOST/"* ]] || die "Pebble's directory does not use $ACME_HOST in its URLs: $body"

	# The same two paths EE's ACME client can take: Guzzle over ext-curl (verify=true, no CA option) and PHP streams.
	# Pebble rejects requests without a User-Agent (Guzzle always sends one).
	ACME_HOST=$ACME_HOST TOS_MARKER=$TOS_MARKER php <<-'PHP' >&2 || die 'PHP does not reach Pebble with default TLS verification'
		<?php
		$u = 'https://' . getenv('ACME_HOST') . '/directory';
		$m = getenv('TOS_MARKER');
		$c = curl_init($u);
		curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'ee-pebble-verify']);
		$a = curl_exec($c);
		if (false === $a || false === strpos($a, $m)) {
			fwrite(STDERR, 'php curl: ' . json_encode(['error' => curl_error($c), 'url' => $u, 'marker' => $m, 'code' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'ip' => curl_getinfo($c, CURLINFO_PRIMARY_IP), 'body' => substr((string) $a, 0, 300)]) . "\n");
			exit(1);
		}
		$b = @file_get_contents($u, false, stream_context_create(['http' => ['user_agent' => 'ee-pebble-verify']]));
		if (false === $b || false === strpos($b, $m)) { fwrite(STDERR, 'php stream: ' . json_encode([error_get_last(), substr((string) $b, 0, 300)]) . "\n"); exit(1); }
		echo 'php curl + stream reach Pebble; ', json_encode(['curl.cainfo' => ini_get('curl.cainfo'), 'openssl' => openssl_get_cert_locations()['default_cert_file'], 'ssl' => curl_version()['ssl_version']]), "\n";
	PHP

	probe="probe-$RANDOM.$PEBBLE_DOMAIN"
	resolved=$(getent ahostsv4 "$probe" | awk 'NR==1{print $1}')
	[[ $resolved == "$PEBBLE_TARGET_IP" ]] || die "$probe resolves to '$resolved', not $PEBBLE_TARGET_IP"
	resolved=$(getent ahostsv4 "www.$probe" | awk 'NR==1{print $1}')
	[[ $resolved == "$PEBBLE_TARGET_IP" ]] || die "www.$probe resolves to '$resolved', not $PEBBLE_TARGET_IP"

	# Everything else must still resolve normally, on the host and in containers on other networks (like EE's sites).
	resolved=$(getent ahostsv4 "$PUBLIC_PROBE" | awk 'NR==1{print $1}')
	[[ -n $resolved && $resolved != "$PEBBLE_TARGET_IP" ]] || die "$PUBLIC_PROBE resolves to '$resolved' on the host"
	log "resolved upstreams for containers: $(grep '^nameserver' /run/systemd/resolve/resolv.conf | tr '\n' ' ')"
	docker network create ee-pebble-probe >/dev/null
	body=$(docker run --rm --network ee-pebble-probe "$PEBBLE_DNS_IMAGE" sh -c \
		"nslookup $probe | tail -n +3; wget -q -O /dev/null https://$PUBLIC_PROBE/ && echo public-https-ok" 2>&1) || true
	docker network rm ee-pebble-probe >/dev/null
	log "container view of $probe: $(tr '\n' ' ' <<<"$body")"
	[[ $body == *public-https-ok* ]] || die "containers can no longer reach https://$PUBLIC_PROBE/: $body"

	while read -r _ ip; do
		[[ $ip == *:* ]] && ip="[$ip]"
		if curl -sS --connect-timeout 5 --resolve "$ACME_HOST:443:$ip" -o /dev/null "https://$ACME_HOST/directory" 2>/dev/null; then
			die "real Let's Encrypt at $ip is still reachable"
		fi
	done < <(grep -E '^(ip6?tables) -D OUTPUT' "$STATE" | awk '{print $1, $5}')
	log "verified: $ACME_HOST -> Pebble $PEBBLE_IP (system trust), *.$PEBBLE_DOMAIN -> $PEBBLE_TARGET_IP, real LE blocked"
}

cmd_block() {
	need_root
	[[ ! -e $STATE ]] || die "$STATE exists; run teardown first"
	mkdir -p "$PEBBLE_DIR"
	: >"$STATE"

	local ip resolved
	for ip in $(public_ips ahostsv4 "$ACME_HOST") $(public_ips ahostsv6 "$ACME_HOST") "$BLACKHOLE_IP"; do
		block_ip "$ip"
	done
	printf '%s\t%s %s\n' "$BLACKHOLE_IP" "$ACME_HOST" "$MARK" >>/etc/hosts
	remember "sed -i '/ $MARK\$/d' /etc/hosts"

	resolved=$(getent ahosts "$ACME_HOST" | awk 'NR==1{print $1}')
	[[ $resolved == "$BLACKHOLE_IP" ]] || die "$ACME_HOST resolves to '$resolved', not the blackhole $BLACKHOLE_IP"
	if curl -sS --connect-timeout 5 -o /dev/null "https://$ACME_HOST/directory" 2>/dev/null; then
		die "$ACME_HOST is still reachable"
	fi
	while read -r _ ip; do
		[[ $ip == "$BLACKHOLE_IP" ]] && continue
		[[ $ip == *:* ]] && ip="[$ip]"
		if curl -sS --connect-timeout 5 --resolve "$ACME_HOST:443:$ip" -o /dev/null "https://$ACME_HOST/directory" 2>/dev/null; then
			die "real Let's Encrypt at $ip is still reachable"
		fi
	done < <(grep -E '^(ip6?tables) -D OUTPUT' "$STATE" | awk '{print $1, $5}')
	log "verified: $ACME_HOST -> $BLACKHOLE_IP (rejected), real LE blocked"
}

cmd_reach() {
	local code
	code=$(docker run --rm --network "$PEBBLE_NET" "curlimages/curl:8.11.1" -sS -o /dev/null -w '%{http_code}' \
		--connect-timeout 10 "http://$PEBBLE_TARGET_IP/.well-known/acme-challenge/ee-pebble-probe") ||
		die "a container on $PEBBLE_NET cannot reach http://$PEBBLE_TARGET_IP/ (HTTP-01 would fail)"
	log "container on $PEBBLE_NET -> http://$PEBBLE_TARGET_IP/ answered HTTP $code"
}

cmd_logs() {
	local c
	for c in ee-pebble ee-pebble-front ee-pebble-dns; do
		echo "===== $c"
		docker logs "$c" 2>&1 || true
	done
}

cmd_teardown() {
	need_root
	[[ -f $STATE ]] || { log 'nothing to tear down'; return 0; }
	local line
	# Undo in reverse order; keep going on errors so a partial setup is still cleaned up.
	while IFS= read -r line; do
		bash -c "$line" >/dev/null 2>&1 || log "undo failed (ignored): $line"
	done < <(tac "$STATE")
	resolvectl flush-caches 2>/dev/null || true
	rm -rf "$PEBBLE_DIR"
	log 'teardown done'
}

case ${1:-} in
	setup) cmd_setup ;;
	block) cmd_block ;;
	verify) cmd_verify ;;
	reach) cmd_reach ;;
	logs) cmd_logs ;;
	teardown) cmd_teardown ;;
	*)
		sed -n '2,13p' "$0" >&2
		exit 2
		;;
esac
