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
