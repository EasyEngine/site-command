Feature: Share a site
  `ee site share` guards. Sharing itself needs the retired ngrok v2 download and an ngrok account, so it is not exercised.

  Scenario: Disabling a share that is not running fails
    Given the html site "bh-site-command-90.test" exists
    When I run 'bin/ee site share bh-site-command-90.test --disable'
    Then the command should fail
    And STDERR should contain "bh-site-command-90.test does not have active share running."

  Scenario: Refreshing a share that is not running fails
    Given the html site "bh-site-command-90.test" exists
    When I run 'bin/ee site share bh-site-command-90.test --refresh'
    Then the command should fail
    And STDERR should contain "bh-site-command-90.test does not have active share running."

  Scenario: SSL sites cannot be shared
    Given the site "bh-site-command-91.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site share bh-site-command-91.test'
    Then the command should fail
    And STDERR should contain "site share is not yet supported for ssl sites."
    And the file "{EE_ROOT_DIR}/services/ngrok/ngrok" should not exist
