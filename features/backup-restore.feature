@remote
Feature: Backup and restore
  `ee site backup` / `ee site restore` against the REAL rclone remote from config.yml (rclone-path).
  Only <rclone-path>/bh-site-command-* is touched; it is purged after the feature.

  Background:
    Given the EasyEngine config value "rclone-path" is set

  Scenario: Back up an html site
    Given the html site "bh-site-command-140.test" exists
    And there are no remote backups for site "bh-site-command-140.test"
    And I write "bh-site-command-marker-140" to the file "{SITES_DIR}/bh-site-command-140.test/app/htdocs/bh-marker.txt"
    When I run 'bin/ee site backup bh-site-command-140.test'
    Then the command should succeed
    And STDOUT should contain "Backup uploaded to remote storage."
    And STDOUT should contain "Backup created successfully."
    And the remote backup storage should have 1 backup for site "bh-site-command-140.test"
    And the directory "{BACKUP_DIR}/bh-site-command-140.test" should not exist
    # PR #480 keeps the lock file (it becomes an flock); drop this line when it merges.
    And the file "{BACKUP_DIR}/bh-site-command-140.test.lock" should not exist
    When I run 'bin/ee site backup bh-site-command-140.test --list'
    Then the command should succeed
    And STDOUT should match "/^\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/m"
    When I save the first match of "/^(\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})$/m" in STDOUT as "BACKUP_ID"
    Then the remote backup "{BACKUP_ID}" of site "bh-site-command-140.test" should contain the file "metadata.json"
    And the remote backup "{BACKUP_ID}" of site "bh-site-command-140.test" should contain the file "bh-site-command-140.test.zip"
    And the remote backup "{BACKUP_ID}" of site "bh-site-command-140.test" should contain the file "conf.zip"

  Scenario: Restore the latest backup
    Given the html site "bh-site-command-141.test" exists
    And there are no remote backups for site "bh-site-command-141.test"
    And I write "original" to the file "{SITES_DIR}/bh-site-command-141.test/app/htdocs/bh-marker.txt"
    When I run 'bin/ee site backup bh-site-command-141.test'
    Then the command should succeed
    When I write "changed" to the file "{SITES_DIR}/bh-site-command-141.test/app/htdocs/bh-marker.txt"
    And I remove the file "{SITES_DIR}/bh-site-command-141.test/app/htdocs/index.html"
    And I run 'bin/ee site restore bh-site-command-141.test'
    Then the command should succeed
    And STDOUT should contain "Site restored successfully."
    And the file "{SITES_DIR}/bh-site-command-141.test/app/htdocs/bh-marker.txt" should contain "original"
    And the file "{SITES_DIR}/bh-site-command-141.test/app/htdocs/index.html" should exist
    # PR #480 keeps the lock file (it becomes an flock); drop this line when it merges.
    And the file "{BACKUP_DIR}/bh-site-command-141.test.lock" should not exist
    When I wait up to 60 seconds for "http://bh-site-command-141.test/bh-marker.txt" to return 200
    Then the response body should contain "original"

  Scenario: Restore a specific backup by id
    Given the html site "bh-site-command-142.test" exists
    And there are no remote backups for site "bh-site-command-142.test"
    And I write "first" to the file "{SITES_DIR}/bh-site-command-142.test/app/htdocs/bh-marker.txt"
    When I run 'bin/ee site backup bh-site-command-142.test'
    Then the command should succeed
    When I run 'bin/ee site backup bh-site-command-142.test --list'
    And I save the first match of "/^(\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})$/m" in STDOUT as "FIRST_ID"
    And I write "second" to the file "{SITES_DIR}/bh-site-command-142.test/app/htdocs/bh-marker.txt"
    And I wait for the next second
    And I run 'bin/ee site backup bh-site-command-142.test'
    Then the command should succeed
    And the remote backup storage should have 2 backups for site "bh-site-command-142.test"
    When I run 'bin/ee site restore bh-site-command-142.test --id={FIRST_ID}'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-142.test/app/htdocs/bh-marker.txt" should contain "first"

  Scenario: Restoring an unknown backup id fails
    Given the html site "bh-site-command-140.test" exists
    When I run 'bin/ee site restore bh-site-command-140.test --id=1000000000_2001-09-09-01-46-40'
    Then the command should fail
    And STDERR should contain "Invalid backup ID provided."

  Scenario: A disabled site cannot be backed up but its backups can be listed
    Given the html site "bh-site-command-143.test" exists
    And there are no remote backups for site "bh-site-command-143.test"
    When I run 'bin/ee site backup bh-site-command-143.test'
    Then the command should succeed
    When I run 'bin/ee site disable bh-site-command-143.test'
    And I run 'bin/ee site backup bh-site-command-143.test'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-143.test is not enabled."
    When I run 'bin/ee site backup bh-site-command-143.test --list'
    Then the command should succeed
    And STDOUT should match "/^\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/m"

  # SC-11: "No remote backups found." was never printed.
  Scenario: Listing the backups of a site without backups says so
    Given the html site "bh-site-command-149.test" exists
    And there are no remote backups for site "bh-site-command-149.test"
    When I run 'bin/ee site backup bh-site-command-149.test --list'
    Then the command should succeed
    And STDOUT should contain "No remote backups found."

  Scenario: A malformed --dash-auth is rejected
    Given the html site "bh-site-command-140.test" exists
    When I run 'bin/ee site backup bh-site-command-140.test --dash-auth=no-separator'
    Then the command should fail
    And STDERR should contain "Invalid --dash-auth format. Expected: backup-id:backup-verification-token"

  Scenario: --dash-auth needs ed-api-url in the config
    Given the EasyEngine config value "ed-api-url" is not set
    And the html site "bh-site-command-140.test" exists
    When I run 'bin/ee site backup bh-site-command-140.test --dash-auth=bh-id:bh-token'
    Then the command should fail
    And STDERR should contain "ed-api-url is not configured."

  # The lock file is also held with flock(1): develop only checks that the file exists, PR #480 only honours a held flock.
  Scenario: A lock file of another backup blocks the backup
    Given the html site "bh-site-command-144.test" exists
    And I write "lock" to the file "{BACKUP_DIR}/bh-site-command-144.test.lock"
    When I run 'flock {BACKUP_DIR}/bh-site-command-144.test.lock bin/ee site backup bh-site-command-144.test'
    Then the command should fail
    And STDERR should contain "Another backup/restore process is running. Please wait for it to complete."

  # SC-9: a failing restore left <site>.lock behind, so every later backup/restore of the site was refused.
  # Asserted through the next backup, not the file: PR #480 (flock) keeps the file.
  Scenario: A failed restore does not block later backups
    Given the html site "bh-site-command-145.test" exists
    And there are no remote backups for site "bh-site-command-145.test"
    When I run 'bin/ee site restore bh-site-command-145.test'
    Then the command should fail
    When I run 'bin/ee site backup bh-site-command-145.test'
    Then the command should succeed
    And STDERR should not contain "Another backup/restore process is running."

  # BKP-1: a restore reused files a previous restore left in the backup directory, even for another --id.
  Scenario: Restoring by id ignores files left behind by an earlier restore
    Given the html site "bh-site-command-146.test" exists
    And there are no remote backups for site "bh-site-command-146.test"
    And I write "A" to the file "{SITES_DIR}/bh-site-command-146.test/app/htdocs/bh-marker.txt"
    When I run 'bin/ee site backup bh-site-command-146.test'
    And I run 'bin/ee site backup bh-site-command-146.test --list'
    And I save the first match of "/^(\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})$/m" in STDOUT as "A_ID"
    And I write "B" to the file "{SITES_DIR}/bh-site-command-146.test/app/htdocs/bh-marker.txt"
    And I wait for the next second
    And I run 'bin/ee site backup bh-site-command-146.test'
    And I run 'bin/ee site backup bh-site-command-146.test --list'
    And I save the first match of "/^(\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})$/m" in STDOUT as "B_ID"
    And I download the remote backup "{B_ID}" of site "bh-site-command-146.test" into the local backup directory
    And I run 'bin/ee site restore bh-site-command-146.test --id={A_ID}'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-146.test/app/htdocs/bh-marker.txt" should contain "A"

  # SC-10: retention only pruned when count > no-of-backups + 1, so one backup too many was kept.
  Scenario: Old backups are pruned down to no-of-backups
    Given the EasyEngine config value "no-of-backups" is "3"
    And the html site "bh-site-command-147.test" exists
    And there are no remote backups for site "bh-site-command-147.test"
    When I run 'bin/ee site backup bh-site-command-147.test'
    Then the command should succeed
    And I wait for the next second
    When I run 'bin/ee site backup bh-site-command-147.test'
    Then the command should succeed
    And I wait for the next second
    When I run 'bin/ee site backup bh-site-command-147.test'
    Then the command should succeed
    And I wait for the next second
    When I run 'bin/ee site backup bh-site-command-147.test'
    Then the command should succeed
    And the remote backup storage should have at most the configured "no-of-backups" backups for site "bh-site-command-147.test"

  @wp
  Scenario: Back up and restore a WordPress site
    Given "ee" is installed
    And the site "bh-site-command-148.test" exists, created with "--type=wp"
    And there are no remote backups for site "bh-site-command-148.test"
    When I run "bin/ee shell bh-site-command-148.test --skip-tty --command='wp option update blogname bh-site-command-before'"
    Then the command should succeed
    When I run 'bin/ee site backup bh-site-command-148.test'
    Then the command should succeed
    And STDOUT should contain "Backup created successfully."
    When I run "bin/ee shell bh-site-command-148.test --skip-tty --command='wp option update blogname bh-site-command-after'"
    And I run 'bin/ee site restore bh-site-command-148.test'
    Then the command should succeed
    And STDOUT should contain "Site restored successfully."
    When I run "bin/ee shell bh-site-command-148.test --skip-tty --command='wp option get blogname'"
    Then STDOUT should contain "bh-site-command-before"
    When I wait up to 60 seconds for "http://bh-site-command-148.test/" to return 200

  # LOG-3: restoring a WP site printed its DB password (wp config set output).
  @wp
  Scenario: Restoring a WordPress site does not print its database password
    Given "ee" is installed
    And the site "bh-site-command-148.test" exists, created with "--type=wp"
    When I run 'bin/ee site info bh-site-command-148.test --format=json'
    And I save the JSON field "db_password" in STDOUT as "DB_PASSWORD"
    And I run 'bin/ee site backup bh-site-command-148.test'
    Then the command should succeed
    When I run 'bin/ee site restore bh-site-command-148.test'
    Then the command should succeed
    And STDOUT should not contain "{DB_PASSWORD}"
    And STDERR should not contain "{DB_PASSWORD}"

  # Regression check (fixed in v4.13.1, EasyEngine/site-command#504): `wp core download` (used by restore) writes a corrupted WordPress 7.x tree with WP-CLI 2.12.
  @wp
  Scenario: A restored WordPress core passes checksum verification
    Given "ee" is installed
    And the site "bh-site-command-148.test" exists, created with "--type=wp"
    When I run 'bin/ee site backup bh-site-command-148.test'
    Then the command should succeed
    When I run 'bin/ee site restore bh-site-command-148.test'
    Then the command should succeed
    When I run "bin/ee shell bh-site-command-148.test --skip-tty --command='wp core verify-checksums'"
    Then the command should succeed

  # BKP-7: a non-numeric or negative no-of-backups made the prune delete every backup, the one just uploaded included.
  Scenario: An invalid no-of-backups keeps the backups and warns
    Given the html site "bh-site-command-150.test" exists
    And there are no remote backups for site "bh-site-command-150.test"
    When I run 'sed "/^no-of-backups:/d" {EE_ROOT_DIR}/config/config.yml > {TMP}/config.yml && echo "no-of-backups: three" >> {TMP}/config.yml'
    And I run 'EE_CONFIG_PATH={TMP}/config.yml bin/ee site backup bh-site-command-150.test'
    Then the command should succeed
    When I wait for the next second
    And I run 'EE_CONFIG_PATH={TMP}/config.yml bin/ee site backup bh-site-command-150.test'
    Then the command should succeed
    And STDERR should contain "Invalid no-of-backups value"
    And the remote backup storage should have 2 backups for site "bh-site-command-150.test"

  # BKP-8: backups had no host identity, so hosts sharing rclone-path and a site name restored (and pruned) each other's.
  Scenario: Hosts that share a remote keep their backups apart
    Given the html site "bh-site-command-151.test" exists
    And there are no remote backups for site "bh-site-command-151.test"
    When I run 'sed "/^backup-host-id:/d" {EE_ROOT_DIR}/config/config.yml > {TMP}/a.yml && cp {TMP}/a.yml {TMP}/b.yml && echo "backup-host-id: bh-site-command-host-a" >> {TMP}/a.yml && echo "backup-host-id: bh-site-command-host-b" >> {TMP}/b.yml'
    And I write "host-a" to the file "{SITES_DIR}/bh-site-command-151.test/app/htdocs/bh-marker.txt"
    And I run 'EE_CONFIG_PATH={TMP}/a.yml bin/ee site backup bh-site-command-151.test'
    Then the command should succeed
    When I write "host-b" to the file "{SITES_DIR}/bh-site-command-151.test/app/htdocs/bh-marker.txt"
    And I wait for the next second
    And I run 'EE_CONFIG_PATH={TMP}/b.yml bin/ee site backup bh-site-command-151.test'
    Then the command should succeed
    When I run 'EE_CONFIG_PATH={TMP}/a.yml bin/ee site restore bh-site-command-151.test'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-151.test/app/htdocs/bh-marker.txt" should contain "host-a"
    When I run 'EE_CONFIG_PATH={TMP}/a.yml bin/ee site backup bh-site-command-151.test --list'
    Then the command should succeed
    And STDOUT should match "/^bh-site-command-host-b\/\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/m"

  # BKP-12: without any backup the restore failed with "Failed to get remote backup size." (local remotes).
  Scenario: Restoring a site without backups says so
    Given the html site "bh-site-command-155.test" exists
    And there are no remote backups for site "bh-site-command-155.test"
    When I run 'bin/ee site restore bh-site-command-155.test'
    Then the command should fail
    And STDERR should contain "No backups found for bh-site-command-155.test."

  # BKP-14: `rm -rf app/*` skipped dotfiles, so files that are not in the backup survived the restore.
  Scenario: A restore removes files that are not in the backup, dotfiles too
    Given the html site "bh-site-command-154.test" exists
    And there are no remote backups for site "bh-site-command-154.test"
    When I run 'bin/ee site backup bh-site-command-154.test'
    Then the command should succeed
    When I write "stale" to the file "{SITES_DIR}/bh-site-command-154.test/app/.bh-stale"
    And I write "stale" to the file "{SITES_DIR}/bh-site-command-154.test/app/htdocs/.bh-stale"
    And I run 'bin/ee site restore bh-site-command-154.test'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-154.test/app/.bh-stale" should not exist
    And the file "{SITES_DIR}/bh-site-command-154.test/app/htdocs/.bh-stale" should not exist
    And the file "{SITES_DIR}/bh-site-command-154.test/app/htdocs/index.html" should exist

  # BKP-9: a WordPress backup held only wp-config.php, wp-content and the DB; other webroot files were lost.
  @wp
  Scenario: A WordPress backup holds the files next to WordPress
    Given "ee" is installed
    And the site "bh-site-command-152.test" exists, created with "--type=wp"
    And there are no remote backups for site "bh-site-command-152.test"
    And I write "User-agent: bh" to the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/robots.txt"
    And I write "<?php echo 'bh-app';" to the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/bh-app/index.php"
    And I write "BH=1" to the file "{SITES_DIR}/bh-site-command-152.test/app/.bh-env"
    When I run 'bin/ee site backup bh-site-command-152.test'
    Then the command should succeed
    When I remove the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/robots.txt"
    And I remove the directory "{SITES_DIR}/bh-site-command-152.test/app/htdocs/bh-app"
    And I remove the file "{SITES_DIR}/bh-site-command-152.test/app/.bh-env"
    And I run 'bin/ee site restore bh-site-command-152.test'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/robots.txt" should contain "User-agent: bh"
    And the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/bh-app/index.php" should exist
    And the file "{SITES_DIR}/bh-site-command-152.test/app/.bh-env" should exist
    And the file "{SITES_DIR}/bh-site-command-152.test/app/htdocs/wp-includes/version.php" should exist
    When I wait up to 60 seconds for "http://bh-site-command-152.test/bh-app/" to return 200
    Then the response body should contain "bh-app"

  # BKP-10: the restore recreated the archived wp-content/uploads symlink and moved the live one into it (uploads/uploads).
  @wp
  Scenario: Symlinked uploads are backed up and restored without a loop
    Given "ee" is installed
    And the site "bh-site-command-153.test" exists, created with "--type=wp"
    And there are no remote backups for site "bh-site-command-153.test"
    When I run 'cd {SITES_DIR}/bh-site-command-153.test/app/htdocs && mkdir -p bh-shared/uploads && rm -rf wp-content/uploads && ln -s ../bh-shared/uploads wp-content/uploads && echo bh-upload > bh-shared/uploads/bh.txt && chown -R www-data:www-data bh-shared && chown -h www-data:www-data wp-content/uploads'
    Then the command should succeed
    When I run 'bin/ee site backup bh-site-command-153.test'
    Then the command should succeed
    When I remove the file "{SITES_DIR}/bh-site-command-153.test/app/htdocs/bh-shared/uploads/bh.txt"
    And I run 'bin/ee site restore bh-site-command-153.test'
    Then the command should succeed
    And the symlink "{SITES_DIR}/bh-site-command-153.test/app/htdocs/wp-content/uploads" should exist
    And the file "{SITES_DIR}/bh-site-command-153.test/app/htdocs/bh-shared/uploads/bh.txt" should contain "bh-upload"
    And the symlink "{SITES_DIR}/bh-site-command-153.test/app/htdocs/bh-shared/uploads/uploads" should not exist
