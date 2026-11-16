#!/bin/bash
# upload-progress.sh <action> [<site>]: helpers for upload-progress.feature, whose steps can't nest quotes.
set -e
ini=/opt/easyengine/sites/$2/config/php/php/conf.d/custom.ini
guard() {
	if [ -z "$CI" ] && [ "$EE_BEHAT_DESTRUCTIVE" != 1 ]; then
		echo "$1 re-runs the upgrade migrations or uses the rclone remote: only on CI or with EE_BEHAT_DESTRUCTIVE=1" >&2
		exit 1
	fi
}
case $1 in
# Before v4.13.3 neither php.ini nor custom.ini set it; On is set explicitly so newer templates and images don't hide the gap.
php-ini-on) sed -i '/session\.upload_progress\.enabled/d' "${ini%/conf.d/custom.ini}/php.ini"; printf '\nsession.upload_progress.enabled = On\n' >> "${ini%/conf.d/custom.ini}/php.ini" ;;
crlf-path) printf 'upload_max_filesize = 100M\r\n[PATH=/var/www/htdocs/x]\r\nsession.upload_progress.enabled = Off\r\n' > "$ini" ;;
append-on) printf '\nsession.upload_progress.enabled = On\n' >> "$ini" ;;
strip) sed -i '/session\.upload_progress\.enabled/d; /Session upload progress is off/d' "$ini" ;;
remove) rm "$ini" ;;
exists) if [ -e "$ini" ] || [ -L "$ini" ]; then echo present; else echo missing; fi ;;
probe) printf '%s' '<?php var_dump(ini_get("session.upload_progress.enabled"));' > "/opt/easyengine/sites/$2/app/htdocs/upe.php" ;;
# Runs the upgrade migrations again, the re-check included.
upgrade)
	guard upgrade
	php <<'PHP'
<?php
$db = new PDO( 'sqlite:/opt/easyengine/db/ee.sqlite' );
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$db->exec( "DELETE FROM migrations WHERE migration LIKE '%recheck_session_upload_progress%'" );
$db->exec( "UPDATE options SET value = '4.13.2' WHERE key = 'version'" );
PHP
	bin/ee cli info ;;
backup | restore) guard "$1"; bin/ee site "$1" "$2" ;;
save) cp "$ini" "/tmp/upload-progress-$2.before" ;;
same) cmp "/tmp/upload-progress-$2.before" "$ini" && echo same ;;
line2) tr -s '\r\n' '\n' < "$ini" | sed -n 2p ;;
clean) rm -f /tmp/upload-progress-bh-site-command-upload-*.before ;;
*) echo "unknown action $1" >&2; exit 1 ;;
esac
