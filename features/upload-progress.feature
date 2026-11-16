@remote @destructive
Feature: Session upload progress stays off
  Re-runs the upgrade migrations on the host (destructive) and backs up to the rclone remote from config.yml.
  Those steps run only on CI or with EE_BEHAT_DESTRUCTIVE=1.

  # "STDOUT should return exactly" checks a scenario's first command, so each check is its own scenario.
  # The scenarios share their sites and run in order.

  Scenario: Sites with a CRLF PATH-only setting, an explicit global On and no custom.ini
    When I run 'bin/ee site create bh-site-command-upload-crlf.test --type=php'
    And I run 'bash features/upload-progress.sh php-ini-on bh-site-command-upload-crlf.test && bash features/upload-progress.sh crlf-path bh-site-command-upload-crlf.test'
    And I run 'bash features/upload-progress.sh probe bh-site-command-upload-crlf.test'
    And I run 'bin/ee site reload bh-site-command-upload-crlf.test'
    And I run 'bin/ee site create bh-site-command-upload-on.test --type=php'
    And I run 'bash features/upload-progress.sh append-on bh-site-command-upload-on.test'
    And I run 'bash features/upload-progress.sh probe bh-site-command-upload-on.test'
    And I run 'bin/ee site reload bh-site-command-upload-on.test'
    And I run 'bash features/upload-progress.sh save bh-site-command-upload-on.test'
    And I run 'bin/ee site create bh-site-command-upload-missing.test --type=php'
    And I run 'bash features/upload-progress.sh remove bh-site-command-upload-missing.test'
    And I run 'bin/ee site reload bh-site-command-upload-missing.test'
    Then return value of command 1 should be 0
    And return value of command 2 should be 0
    And return value of command 3 should be 0
    And return value of command 4 should be 0
    And return value of command 5 should be 0
    And return value of command 6 should be 0
    And return value of command 7 should be 0
    And return value of command 8 should be 0
    And return value of command 9 should be 0
    And return value of command 10 should be 0
    And return value of command 11 should be 0
    And return value of command 12 should be 0

  Scenario: Before the upgrade the PATH-only site runs with upload progress On
    When I run 'curl -s http://bh-site-command-upload-crlf.test/upe.php'
    Then STDOUT should return exactly
    """
    string(1) "1"
    """

  Scenario: Upgrade
    When I run 'bash features/upload-progress.sh upgrade'
    Then return value should be 0
    And STDERR should return something like
    """
    Skipped disabling session.upload_progress for bh-site-command-upload-missing.test: custom.ini is missing or not a regular file.
    """

  Scenario: The PATH-only site runs with upload progress Off
    When I run 'sleep 3; curl -s http://bh-site-command-upload-crlf.test/upe.php'
    Then STDOUT should return exactly
    """
    string(0) ""
    """

  Scenario: The Off line is at the top
    When I run 'bash features/upload-progress.sh line2 bh-site-command-upload-crlf.test'
    Then STDOUT should return exactly
    """
    session.upload_progress.enabled = Off
    """

  Scenario: The explicit On is kept
    When I run 'curl -s http://bh-site-command-upload-on.test/upe.php'
    Then STDOUT should return exactly
    """
    string(1) "1"
    """

  Scenario: The file with the explicit On is unchanged
    When I run 'bash features/upload-progress.sh same bh-site-command-upload-on.test'
    Then STDOUT should return exactly
    """
    same
    """

  Scenario: A missing custom.ini is not created by the upgrade
    When I run 'bash features/upload-progress.sh exists bh-site-command-upload-missing.test'
    Then STDOUT should return exactly
    """
    missing
    """

  Scenario: Run the migration again
    When I run 'bash features/upload-progress.sh save bh-site-command-upload-crlf.test && bash features/upload-progress.sh upgrade'
    Then return value should be 0
    And STDERR should return something like
    """
    Skipped disabling session.upload_progress for bh-site-command-upload-missing.test: custom.ini is missing or not a regular file.
    """

  Scenario: A second run changes nothing
    When I run 'bash features/upload-progress.sh same bh-site-command-upload-crlf.test'
    Then STDOUT should return exactly
    """
    same
    """

  Scenario: Back up a site whose custom.ini lacks the Off line
    When I run 'bin/ee site create bh-site-command-upload-restore.test --type=php'
    And I run 'bash features/upload-progress.sh php-ini-on bh-site-command-upload-restore.test && bash features/upload-progress.sh strip bh-site-command-upload-restore.test'
    And I run 'bash features/upload-progress.sh probe bh-site-command-upload-restore.test'
    And I run 'bin/ee site reload bh-site-command-upload-restore.test'
    And I run 'bash features/upload-progress.sh backup bh-site-command-upload-restore.test'
    Then return value of command 1 should be 0
    And return value of command 2 should be 0
    And return value of command 3 should be 0
    And return value of command 4 should be 0
    And return value of command 5 should be 0

  Scenario: Without the Off line the site runs with upload progress On
    When I run 'curl -s http://bh-site-command-upload-restore.test/upe.php'
    Then STDOUT should return exactly
    """
    string(1) "1"
    """

  Scenario: Restoring that backup turns upload progress off again
    When I run 'bash features/upload-progress.sh restore bh-site-command-upload-restore.test'
    Then return value should be 0
    And STDOUT should return something like
    """
    The restored custom.ini did not set session.upload_progress.enabled, so it was set to Off again.
    """

  Scenario: The restored file has the Off line at the top
    When I run 'bash features/upload-progress.sh line2 bh-site-command-upload-restore.test'
    Then STDOUT should return exactly
    """
    session.upload_progress.enabled = Off
    """

  Scenario: The restored site runs with upload progress Off
    When I run 'sleep 3; curl -s http://bh-site-command-upload-restore.test/upe.php'
    Then STDOUT should return exactly
    """
    string(0) ""
    """

  Scenario: Back up the site without custom.ini
    When I run 'bash features/upload-progress.sh backup bh-site-command-upload-missing.test'
    Then return value should be 0

  Scenario: Restoring a backup without custom.ini warns instead of creating it
    When I run 'bash features/upload-progress.sh restore bh-site-command-upload-missing.test'
    Then return value should be 0
    And STDERR should return something like
    """
    Could not check session.upload_progress in the restored custom.ini: it is missing or not a regular file.
    """

  Scenario: The restore did not create custom.ini
    When I run 'bash features/upload-progress.sh exists bh-site-command-upload-missing.test'
    Then STDOUT should return exactly
    """
    missing
    """

  Scenario: Clean up
    When I run 'bin/ee site delete bh-site-command-upload-crlf.test --yes'
    And I run 'bin/ee site delete bh-site-command-upload-on.test --yes'
    And I run 'bin/ee site delete bh-site-command-upload-missing.test --yes'
    And I run 'bin/ee site delete bh-site-command-upload-restore.test --yes'
    And I run 'bash features/upload-progress.sh clean'
    Then return value of command 1 should be 0
    And return value of command 2 should be 0
    And return value of command 3 should be 0
    And return value of command 4 should be 0
    And return value of command 5 should be 0
