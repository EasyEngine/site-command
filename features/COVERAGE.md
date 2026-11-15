# site-command Behat coverage

Suite for `easyengine/site-command` (the `ee site` command and the html site type). 18 features, 179 scenarios (211 with outlines expanded): main lane 190, `@wp` 10, `@le` 11. Run it the CI way (`.github/workflows/behat.yml`): the files are copied into a core checkout's `features/` and core's `vendor/bin/behat` runs from the core root as root.

## Tags

| Tag | Scenarios | Meaning |
|---|---|---|
| (none of the tags below) | 171 | Local only: `*.test` sites, no public DNS, no remote storage, no global restarts. This is the default safe run. |
| `@known-bug` | 0 | Scenarios that expose open bugs are kept outside this suite and come back with their fixes. The CI lanes still exclude the tag. |
| `@destructive` | 12 | Restarts the GLOBAL nginx-proxy (proxy cache) or runs `ssl-renew --all` (refuses to start when an enabled LE site that isn't the suite's exists). Every scenario leaves the proxy running. |
| `@remote` | 10 | Uses the REAL rclone remote (`rclone-path` in config.yml). Touches only `<rclone-path>/bh-site-command-*`: the rclone path is listed first and only listed suite directories are purged (per site before a scenario, all of them after the feature). |
| `@le` | 11 | Let's Encrypt issuance on `*.ee4-test.rt.gw`. Locally: real LE, at most 4 certificates per full run; names contain `{RUN_ID}`, so repeated runs never hit the duplicate-certificate limit. On CI all 11 run against Pebble (`.github/behat/pebble-setup.sh`: EE's hardcoded LE host is redirected to Pebble, `{LE_ISSUER}` comes from `EE_BEHAT_LE_ISSUER`). The failed-alias rollback and the ACME cleanup on delete are regression checks for EasyEngine/site-command#505. |
| `@le-real` | 0 | Needs the public LE service itself (rate limits, the real chain); never runs on CI. None so far: every `@le` scenario works on Pebble. |
| `@wp` | 10 | Needs the `wp` site type (site-type-wp in the core checkout) and `ee` on PATH; slow. |

Default safe run: `--tags='~@le&&~@remote&&~@destructive&&~@wp'`.

## Environment rules

- Every site, alias, hosts entry and remote backup path contains `bh-site-command-`. `@AfterFeature` deletes only sites matching `(^|\.)bh-site-command-`, never other sites; once none is left it also removes suite-named leftovers of known bugs (proxy `certs/`, `acme-conf/certs|var/`, site dirs, volumes labelled with a suite name); `@remote` features also purge the listed `<rclone-path>/bh-site-command-*` directories. `@AfterScenario` removes hosts entries the scenario added, the scenario's `{TMP}` dir under `/var/tmp`, and `{BACKUP_DIR}/bh-site-command-*` leftovers.
- The file-writing, hosts, delete and rclone steps refuse names and paths without the prefix (or outside `{TMP}`).
- HTTP steps resolve the site name to 127.0.0.1 (whitelisted, so no HTTP auth, and no /etc/hosts entry needed); `through the public IP` goes through `hostname -I` instead; `verified` uses real DNS and full TLS verification (LE only). Redirects are never followed.
- Waits poll (`I wait up to N seconds for URL to return CODE`, `... until the command ... succeeds`); there are no fixed sleeps, except `I wait for the next second` between back-to-back backups of one site (backup ids have one-second resolution).
- Scenarios are independent: each one uses its own site or the idempotent `Given the html site "..." exists` / `exists, created with "..."`. The exception is `le.feature`, which shares three sites in file order to stay within the LE budget (each scenario still creates what it needs when run alone).
- Commands that could hang (the proxy cache purge used to, without a terminal) run under `with a timeout of N seconds`.
- Commands that could prompt (le-mail) are guarded by `Given the EasyEngine config value "le-mail" is set`.
- The failing LE alias uses a `*.invalid` name, so no validation ever reaches a host outside `*.ee4-test.rt.gw`.

## Estimated runtime (4 CPU test host)

html create about 15-20 s, delete 5-10 s, proxy-cache toggle 10-15 s (proxy restart), html backup/restore 15-40 s, WordPress create 1.5-2.5 min, LE issuance 30-60 s.

| Selection | Estimate |
|---|---|
| Default safe run | ~40 min |
| `@destructive` | ~7 min |
| `@remote` (html part) | ~7 min |
| `@wp` (clone/sync + WP backup/restore) | ~16 min |
| `@le` | ~6 min |
| Everything | ~75-80 min |

## Inventory and mapping

Legend: covered / partial / not covered (reason). Feature files are abbreviated: site, create, delete, list, info, en-dis (enable-disable), rr (restart-reload), alias (update-alias), ussl (update-ssl-php), pcache (proxy-cache), clean, share, ssl, redirect, labels, backup (backup-restore), clone (clone-sync), le.

### Routing and global behaviour (`Site_Command::__invoke`, hooks.php)

| Item | Status | Scenarios |
|---|---|---|
| `ee site` usage | covered | site: Check site command is present |
| `ee help site`, `ee help site create` | covered | site: Help lists every site subcommand; Help of site create |
| `ee help site --type=X` routing hook, unknown type error | covered | site: Help for an unregistered site type fails |
| `ee site cmd-dump` (hidden) | covered | site: cmd-dump prints the site command tree as JSON |
| `--type=X` on create, unregistered type error | covered | site: Creating a site of an unregistered type fails; create: Create an html site (`--type=html`) |
| Type auto-detection from the site name / from the cwd | covered | info: The site is detected from the working directory; site outlines (unknown site messages) |
| Wrong site name inside a site dir ("Did you mean") | covered | info: Another site name inside a site directory is rejected with a hint |
| Name normalisation: `http(s)://`, trailing slash, add `.test`, lower-case | covered | create: A site name without a dot...; The URL scheme and a trailing slash...; Upper-case site names...; info: The site name may be given as a URL |
| EE v3 compat flags (`--html`, `--wp`, `--le`, `--mysql`, ...) | partial | create: The EE v3 --html flag (`--wp`/`--php`/`--le` belong to site-type-* / LE budget) |
| Removed EE v3 flags error (`--w3tc`, ...) | not covered yet | the removed-flag message never shows (its scenario comes back with the fix) |
| Unknown parameter / too many positionals / missing required positional | covered | site: Unknown parameters are rejected; Extra positional arguments; Subcommands with a required site name print the usage |
| Missing site outside a site dir (auto_site_name) | covered | site: outline over info, update, enable, disable, restart, reload, clean, ssl-info, backup, restore; ssl-renew scenario |
| Unknown site (get_site_info) | covered | site: outlines over delete, refresh, ssl-verify, ssl, info, enable, disable, restart, update, share |
| Disabled site refused | covered | en-dis: outline over restart, reload, update, refresh, share, backup, restore |
| `site_cleanup` hooks (redis purge, share cleanup) | not covered | redis purge only fires for WP cache sites (site-type-wp suite); share cleanup needs a live ngrok share |
| Signal rollback (pcntl handlers) | not covered | the handlers are never dispatched; needs killing ee mid-create |

### create (html)

| Item | Status | Scenarios |
|---|---|---|
| `<site-name>` happy path: files, .env, main.conf, user.conf, index.html, compose file, nginx container, 3 labelled volumes, network, hosts entry, DB row, HTTP 200 | covered | create: Create an html site; labels: both scenarios |
| Duplicate site | covered | create: Creating a site that already exists fails and keeps the site |
| Docker volume / compose project collision with another site | covered | create: A site whose docker volumes and compose project collide with an existing site is refused (EasyEngine/site-command#503) |
| `--public-dir` | covered | create: Create a site with a custom public directory |
| `--skip-status-check` | covered | create: Create a site without the status check |
| `--alias-domains` (DB, server_name, VIRTUAL_HOST, HTTP on alias) | covered | create: Create a site with alias domains |
| Alias/site conflicts on create | covered | create: A domain that is an alias of another site...; An alias domain that belongs to another site...; not covered yet: the owner lookup matches any alias containing the name (its scenario comes back with the fix) |
| `--ssl=self` | covered | ssl: Create a site with a self-signed certificate; Info of a self-signed site |
| `--ssl=custom` + `--ssl-key/--ssl-crt`, validation, rollback | covered | ssl: Create a site with a custom certificate; Custom SSL needs both...; Custom SSL files must exist |
| `--ssl=inherit` (parent checks, `--wildcard` refusal) | covered | ssl: A subdomain site inherits...; Inheriting needs an existing parent / a parent with SSL / cannot be combined with --wildcard |
| `--ssl=le`, `--ssl=le --wildcard` | covered (@le) | le: Create a site with a Let's Encrypt certificate; A wildcard certificate waits for the DNS challenge |
| `--ssl` with an invalid value on create | not covered | `--ssl` is a flag on create, so no option validation; would only fail inside init_ssl after the containers are up |
| www redirect written only when www resolves; https redirect | covered | redirect: all scenarios |
| Rollback on failure (catch_clean) leaves no site/dir | covered | ssl: custom/inherit failure scenarios (site and dir absent) |
| Site name validation | covered (regression check, EasyEngine/site-command#509) | create: Site names that are not valid hostnames are rejected... |
| Global admin-tools auth init on create | not covered | belongs to auth-command / admin-tools-command suites |
| macOS paths (dnsmasq, IS_DARWIN) | not covered | Linux test host |

### delete

| Item | Status | Scenarios |
|---|---|---|
| `--yes`: containers, volumes, network, site root, hosts entry, redirect conf, DB row, clean STDERR | covered | delete: Delete an html site and everything that belongs to it |
| Second delete / unknown site | covered | delete: same scenario; site: outline |
| Prompt answered n / y | covered | delete: Answering no...; Answering yes... |
| Disabled site | covered | delete: A disabled site can be deleted |
| SSL files removed (self, LE + revoke, leftover cert of a site without SSL) | covered | delete: Deleting a self-signed site removes its certificate; Deleting a site without SSL removes a leftover certificate; le: Deleting a Let's Encrypt site... |
| Proxy cache config removed | covered (@destructive) | pcache: Deleting a proxy-cached site removes its proxy config |
| Alias domains freed | covered | delete: Deleting a site frees its alias domains |
| LE leftovers (www and alias ACME state) | covered | le: Deleting a Let's Encrypt site leaves no ACME state behind |
| DB/user cleanup for external DB sites, cron rows | not covered | only php/wp sites have DBs and crons (site-type-*/cron-command suites) |

### list / info

| Item | Status | Scenarios |
|---|---|---|
| list `--format=table/text/json/csv/yaml/count`, invalid format | covered | list: all |
| `--enabled`, `--disabled`, both | covered | list: List only enabled/disabled; Both filters |
| "No sites found!" | not covered | depends on the whole host having no (enabled/disabled) sites |
| info table (Site, Site Root, Alias Domains, SSL, SSL Wildcard) | covered | info: Show site info as a table; ssl: Info of a self-signed site; alias: Add alias domains |
| info `--format=json`, invalid format | covered | info: Show site info as JSON; An unsupported format is rejected |
| info on a disabled site, URL-style name, cwd detection | covered | info: remaining scenarios |

### enable / disable / refresh / restart / reload / clean

| Item | Status | Scenarios |
|---|---|---|
| disable (containers removed, volumes kept, DB, proxy stops serving), idempotent | covered | en-dis: Disable a site; Disabling a disabled site is harmless |
| enable, already enabled, `--force`, `--refresh`, `--verify` | covered | en-dis: Enable...; Enabling an enabled site fails; Force-enable...; Enable with --refresh...; Enable with verification... |
| `refresh` subcommand | covered | en-dis: refresh is a shortcut for enable --refresh |
| enable/disable manage the www redirect | covered | redirect: Disable removes the redirect and enable restores it; not covered yet: re-enabling drops the www redirect when the www check fails (its scenario comes back with the fix) |
| enable post-configuration of postfix / admin-tools / mailhog | not covered | html sites have none (site-type-*, admin-tools, mailhog suites) |
| restart (no flag, `--nginx`, `--all`), unknown service flag | covered | rr: Restart the site containers (outline); Restart rejects services... |
| restart refuses a broken nginx config | covered | rr: Restart refuses a broken nginx config and keeps the site serving |
| reload (no flag, `--nginx`, `--all`), custom config applied | covered | rr: Reload nginx... (outline); Custom nginx config is applied on reload |
| reload with a broken config | not covered yet | reload exits 0 when `nginx -t` fails (its scenario comes back with the fix) |
| `custom-compose` config branch of restart/enable | not covered | needs a custom compose setup (config change) |
| clean: no flags, `--object`, `--page`, `--proxy` without proxy cache | covered | clean: first four scenarios |
| clean `--proxy` with proxy cache | covered (@destructive, regression check for the purge without a terminal) | clean: Cleaning the proxy cache empties it |
| clean without flags on a proxy-cached html site | not covered yet | clean without flags always fails on html sites (its scenario comes back with the fix) |
| clean object/page caches with redis | not covered | only WP cache sites have them (site-type-wp suite) |

### update

| Item | Status | Scenarios |
|---|---|---|
| `--add-alias-domains` (DB, env, HTTP, info) | covered | alias: Add alias domains |
| already present / partial overlap warning | covered | alias: Adding an alias that is already present fails; Adding present and new aliases... |
| `--delete-alias-domains`, primary, not a subset | covered | alias: Delete an alias domain; The primary domain cannot be deleted; Deleting an alias the site does not have fails |
| alias that is a site / alias of another site | covered | alias: Another site cannot be added...; An alias of another site cannot be added |
| add + delete in one call, wildcard alias | covered | alias: Add and delete aliases in one call; A wildcard alias serves every subdomain |
| alias change on self (keeps HTTPS), on LE (reissue), LE alias failing validation (rolled back) | covered | alias: Changing aliases on a self-signed site keeps HTTPS; le: alias scenarios; An alias that fails validation is rejected... |
| alias label validation | covered (regression check, EasyEngine/site-command#502) | alias: Alias domains with a label starting with an underscore are rejected |
| alias change on custom SSL (warning) | not covered | low value; same code path as self apart from the warning text |
| `--ssl=self` on a plain site, twice, `--ssl=off`, off on plain | covered | ussl: first four scenarios |
| `--wildcard` refusals, invalid `--ssl`, `--ssl=off --wildcard` | covered | ussl: Switching a normal site to wildcard SSL is refused; An unknown SSL type is rejected; --wildcard cannot be combined with --ssl=off |
| `--ssl=inherit` via update | covered | ussl: Inherit SSL from a self-signed parent through update; not covered yet: update skips the wildcard parent check (its scenario comes back with the fix) |
| `--ssl=le` via update | covered (@le) | le: Enable Let's Encrypt on an existing site |
| `--ssl=custom` via update | covered (regression check, EasyEngine/site-command#489) | ussl: Enable custom SSL on an existing site |
| off on a created self-signed site, wildcard flag consistency | covered (regression checks, EasyEngine/site-command#505) | ussl: SSL can be disabled on a site created with self-signed SSL; Enabling self-signed SSL through update records it as wildcard... |
| `--php` on html (fails, site intact), invalid version | covered | ussl: Changing the PHP version of an html site fails...; An unsupported PHP version is rejected; not covered yet: the refusal comes late, with a raw IOException and a leftover `.backup/` (its scenario comes back with the fix) |
| `--php` real version switch (config backup/restore, sendmail path for 5.6) | not covered | php/wp sites only (site-type-php/wp suites) |
| `--proxy-cache=on/off`, twice, custom sizes, invalid sizes | covered (@destructive) | pcache: all but the invalid switch |
| invalid `--proxy-cache` value | not covered yet | any value is accepted and anything but `on` disables the cache (its scenario comes back with the fix) |
| proxy cache for aliases (existing, newly added, deleted), failed alias sync leaves a loadable config | covered (@destructive) | pcache: Alias domains get proxy cache locations...; Deleting an alias removes its proxy cache location; A failed proxy cache alias sync leaves a config nginx can load |
| proxy cache `subdom` location (`*.site_location`) | not covered | WP subdom only (site-type-wp suite) |

### SSL commands

| Item | Status | Scenarios |
|---|---|---|
| ssl-info: self, custom, inherit (parent cert file), no SSL, LE, pending wildcard | covered | ssl: ssl-info scenarios; le: create and wildcard scenarios |
| ssl-info `--format=yaml`, invalid format | covered | ssl: ssl-info supports other formats; ssl-info rejects an unsupported format |
| ssl-info `--get-dns-records`: non-DNS site, DNS-01 site | covered | ssl: ssl-info DNS records of a site that does not use DNS-01; le: A wildcard certificate waits... |
| ssl-info `--get-dns-records` on a self-signed (wildcard) site | not covered yet | self-signed wildcard sites are treated as DNS-01 (its scenario comes back with the fix) |
| ssl-renew guards: no SSL, self, inherit | covered | ssl: ssl-renew only renews Let's Encrypt certificates (outline); ssl-renew refuses inherited certificates |
| ssl-renew not due (no reissue), `--all` wildcard skip | covered (@le, `--all` also @destructive) | le: ssl-renew leaves a certificate that is not due alone; ssl-renew --all skips wildcard sites... |
| ssl-renew `--force`, expired-cert path | not covered | LE budget (a forced renew is one more issuance); expiry cannot be simulated without a fake CA |
| ssl-verify / `ssl` alias: unknown site | covered | site: outline |
| ssl-verify on a valid LE site | not covered yet | ssl-verify checks only the site name of an HTTP-01 certificate that includes www and fails (its scenario comes back with the fix) |
| ssl-verify completing a DNS-01 order, Cloudflare DNS solver | not covered | needs Cloudflare API credentials (not configured) |
| Self-signed root CA validity | covered (regression check, EasyEngine/site-command#513) | ssl: Self-signed site certificates verify against the EasyEngine root CA |
| Self-signed certificate covering alias domains | not covered yet | the certificate leaves the alias domains out (its scenario comes back with the fix) |
| Failed inherit error message | not covered yet | the message dumps the parent site object (its scenario comes back with the fix) |
| SSL policy (`ssl-policy` config) | not covered | global config change (@destructive) with little behaviour beyond the template |

### share

| Item | Status | Scenarios |
|---|---|---|
| `--disable` / `--refresh` without an active share, SSL site refused, required name | covered | share: all; site: usage outline |
| Actual share (ngrok download, tunnel, `--token`), one share at a time, share cleanup on delete | not covered | the ngrok v2 binary URL (bin.equinox.io) is retired and ngrok needs an account token; external network |

### backup / restore (@remote)

| Item | Status | Scenarios |
|---|---|---|
| backup html: upload, success output, remote files (metadata.json, <site>.zip, conf.zip), local cleanup, lock released | covered | backup: Back up an html site |
| `--list` | covered | backup: Back up an html site; A disabled site cannot be backed up but its backups can be listed |
| `--list` with no backups | not covered yet | the "No remote backups found." message is never printed (its scenario comes back with the fix) |
| restore latest, restore `--id`, unknown id | covered | backup: Restore the latest backup; Restore a specific backup by id; Restoring an unknown backup id fails |
| disabled site | covered | backup: A disabled site cannot be backed up...; en-dis outline |
| `--dash-auth` format and ed-api-url check | covered | backup: A malformed --dash-auth is rejected; --dash-auth needs ed-api-url in the config |
| `--dash-auth` success/failure callbacks, rollback of the uploaded backup | not covered | needs an EasyDash API endpoint (`ed-api-url`) |
| per-site lock blocks, lock left by a failed restore | covered | backup: A lock file of another backup blocks the backup (held with flock(1), so it holds with or without EasyEngine/site-command#480); not covered yet: a failed restore leaves the lock file and blocks later backups (its scenario comes back with the fix) |
| stale download reuse | not covered yet | a restore by id reuses files an earlier restore left behind (its scenario comes back with the fix) |
| retention (`no-of-backups`) | not covered yet | retention keeps one backup more than `no-of-backups` (its scenario comes back with the fix) |
| WP backup/restore (DB, wp-content, core download) | covered (@wp; core checksums are a regression check, EasyEngine/site-command#504) | backup: Back up and restore a WordPress site; A restored WordPress core passes checksum verification; not covered yet: the restore prints the database password (its scenario comes back with the fix) |
| custom docker-compose files in backups | not covered | needs a custom compose setup |
| disk-space checks, rclone/7z missing, apt-get auto-install, global backup lock wait | not covered | needs a full disk, uninstalled tools or a concurrent backup; the auto-install path needs a pending kernel upgrade |
| partial SQL dump left in the webroot after a failed dump | not covered | needs a failing mysqldump (fault injection) |
| invalid `no-of-backups` | not covered yet | an invalid value deletes backups without a warning (its scenario comes back with the fix) |
| per-host remote layout, hosts sharing `rclone-path` | not covered yet | hosts that share a remote mix their backups (its scenario comes back with the fix) |
| restore without any backup | not covered yet | the restore doesn't say that there is no backup (its scenario comes back with the fix) |
| files and dotfiles not in the backup | not covered yet | a restore keeps files that are not in the backup (its scenario comes back with the fix) |
| WP webroot files beside WordPress, symlinked uploads | not covered yet | files next to WordPress are left out and symlinked uploads loop (their scenarios come back with the fixes) |
| restore rollback, disk-full extraction | not covered | needs fault injection or a small filesystem |

### clone / sync

| Item | Status | Scenarios |
|---|---|---|
| Location validation (implicit names, both remote, same site, malformed remote) | covered | clone: first four scenarios |
| Missing source, disabled source, html source, missing destination, html sync | covered | clone: remaining validation scenarios |
| Local WP clone (files, DB, search-replace), existing destination kept | covered (@wp) | clone: Clone a WordPress site on the same host; Cloning onto an existing site fails and keeps that site |
| sync `--files`, `--db`, `--uploads` | covered (@wp) | clone: Sync only files / the database / uploads |
| clone `--ssl=...`, `--wildcard`, cert copy, admin-tools/mailhog replication | not covered | same-host clone of an SSL WP site is possible but slow; LE options need the LE budget |
| Remote clone/sync over SSH, production guard (`env: production`), non-JSON site list output, inherit-site checks on the other host | not covered | needs a second SSH-reachable EE host (or a fake ssh shim); the production guard needs a global config change |

### Not in scope

DB and container migrations under `migrations/` (core upgrade flow), the AcmePhp repository classes (only through LE scenarios), macOS-only code.

## Coverage estimate

Counting the rows above as items: 124 items, 83 covered, 1 partial, 40 not covered (16 of them, marked "not covered yet", are open bugs whose scenarios come back with their fixes), i.e. about 67% of the inventory. Every user-facing subcommand and option of the html type has at least one scenario except `ssl-renew --force`, `clone --ssl/--wildcard` and a live `share`.

## Known bugs left out

24 scenarios that expose open bugs are not part of this suite; each comes back with the PR that fixes its bug. They cover: the www redirect of a site whose next label starts with `w`, and the redirect after re-enabling a site when the www check fails; the owner lookup of an alias that contains another alias; `update --ssl=inherit` skipping the parent check; the inherit error message dumping the parent site object; the self-signed certificate leaving out alias domains; `ssl-info --get-dns-records` on self-signed sites; `ssl-verify` on a valid Let's Encrypt site; `site reload` exiting 0 when `nginx -t` fails; `site clean` without flags on html sites; invalid `--proxy-cache` values; the removed EE v3 cache flags message; `update --php` on html sites failing late; and in backup/restore: `--list` and restore without backups, a failed restore blocking later backups, stale downloads reused by a restore, retention, invalid `no-of-backups`, hosts sharing a remote, files left over by a restore, the database password printed by a WordPress restore, and WordPress webroot files and symlinked uploads.

PRs that change what passing scenarios assert, to revisit when they merge: EasyEngine/site-command#480 keeps `<site>.lock` after a successful backup/restore (drop the two "lock should not exist" lines in backup), EasyEngine/site-command#482 adds a `--list` "No remote backups found." line and new disk-space errors, EasyEngine/site-command#484 and #483 add new restore/backup abort messages.
