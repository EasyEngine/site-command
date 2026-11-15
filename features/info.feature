Feature: Site info
  `ee site info` table and JSON output, site auto-detection from the working directory.

  Background:
    Given the html site "bh-site-command-45.test" exists

  Scenario: Show site info as a table
    When I run 'bin/ee site info bh-site-command-45.test'
    Then the command should succeed
    And STDOUT should match "/\|\s*Site\s*\|\s*http:\/\/bh-site-command-45\.test\s*\|/"
    And STDOUT should match "/\|\s*Site Root\s*\|\s*\/opt\/easyengine\/sites\/bh-site-command-45\.test\s*\|/"
    And STDOUT should match "/\|\s*Alias Domains\s*\|\s*None\s*\|/"
    And STDOUT should match "/\|\s*SSL\s*\|\s*Not Enabled\s*\|/"
    And STDOUT should not contain "SSL Wildcard"

  Scenario: Show site info as JSON
    When I run 'bin/ee site info bh-site-command-45.test --format=json'
    Then the command should succeed
    And STDOUT should be valid JSON
    And the JSON field "site_url" in STDOUT should be "bh-site-command-45.test"
    And the JSON field "site_type" in STDOUT should be "html"
    And the JSON field "site_enabled" in STDOUT should be "1"
    And the JSON field "site_fs_path" in STDOUT should be "{SITES_DIR}/bh-site-command-45.test"
    And the JSON field "alias_domains" in STDOUT should be "bh-site-command-45.test"

  Scenario: The site name may be given as a URL
    When I run 'bin/ee site info http://bh-site-command-45.test/'
    Then the command should succeed
    And STDOUT should contain "http://bh-site-command-45.test"

  Scenario: Info works for a disabled site
    Given the html site "bh-site-command-46.test" exists
    And the site "bh-site-command-46.test" is disabled
    When I run 'bin/ee site info bh-site-command-46.test'
    Then the command should succeed
    And STDOUT should contain "http://bh-site-command-46.test"

  Scenario: The site is detected from the working directory
    When I run 'cd {SITES_DIR}/bh-site-command-45.test && {EE} site info'
    Then the command should succeed
    And STDOUT should contain "http://bh-site-command-45.test"

  Scenario: Another site name inside a site directory is rejected with a hint
    When I run 'cd {SITES_DIR}/bh-site-command-45.test && {EE} site info bh-site-command-47.test'
    Then the command should fail
    And STDERR should contain "bh-site-command-47.test is not a valid site-name. Did you mean `ee site info bh-site-command-45.test`?"

  Scenario: An unsupported format is rejected
    When I run 'bin/ee site info bh-site-command-45.test --format=csv'
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'format'"
