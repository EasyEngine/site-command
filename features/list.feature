Feature: List sites
  `ee site list` formats and the enabled/disabled filters. Other sites may exist on the host, so only this suite's sites are asserted.

  Background:
    Given the html site "bh-site-command-40.test" exists
    And the html site "bh-site-command-41.test" exists
    And the site "bh-site-command-41.test" is disabled

  Scenario: List sites as text
    When I run 'bin/ee site list --format=text'
    Then the command should succeed
    And STDOUT should match "/^bh-site-command-40\.test$/m"
    And STDOUT should match "/^bh-site-command-41\.test$/m"

  Scenario: List sites as a table by default
    # Without a TTY the default format is tab-separated; SHELL_PIPE=0 makes it render as it does in a terminal.
    When I run 'SHELL_PIPE=0 bin/ee site list'
    Then the command should succeed
    And STDOUT should match "/\|\s*site\s*\|\s*status\s*\|/"
    And STDOUT should match "/\|\s*bh-site-command-40\.test\s*\|\s*enabled\s*\|/"
    And STDOUT should match "/\|\s*bh-site-command-41\.test\s*\|\s*disabled\s*\|/"

  Scenario: List sites as JSON
    When I run 'bin/ee site list --format=json'
    Then the command should succeed
    And STDOUT should be valid JSON
    And STDOUT should match "/\{\s*"site":\s*"bh-site-command-40\.test",\s*"status":\s*"enabled"\s*\}/"
    And STDOUT should match "/\{\s*"site":\s*"bh-site-command-41\.test",\s*"status":\s*"disabled"\s*\}/"

  Scenario: List sites as CSV
    When I run 'bin/ee site list --format=csv'
    Then the command should succeed
    And STDOUT should match "/^site,status$/m"
    And STDOUT should match "/^bh-site-command-40\.test,enabled$/m"
    And STDOUT should match "/^bh-site-command-41\.test,disabled$/m"

  Scenario: List sites as YAML
    When I run 'bin/ee site list --format=yaml'
    Then the command should succeed
    And STDOUT should contain "site: bh-site-command-40.test"
    And STDOUT should contain "site: bh-site-command-41.test"

  Scenario: Count sites
    When I run 'bin/ee site list --format=count'
    Then the command should succeed
    And STDOUT should match "/^\s*([2-9]|\d{2,})\s*$/"

  Scenario: List only enabled sites
    When I run 'bin/ee site list --enabled --format=json'
    Then the command should succeed
    And the JSON array in STDOUT should contain an item with "site" set to "bh-site-command-40.test"
    And the JSON array in STDOUT should not contain an item with "site" set to "bh-site-command-41.test"
    And the JSON array in STDOUT should not contain an item with "status" set to "disabled"

  Scenario: List only disabled sites
    When I run 'bin/ee site list --disabled --format=json'
    Then the command should succeed
    And the JSON array in STDOUT should contain an item with "site" set to "bh-site-command-41.test"
    And the JSON array in STDOUT should not contain an item with "site" set to "bh-site-command-40.test"
    And the JSON array in STDOUT should not contain an item with "status" set to "enabled"

  Scenario: Both filters together list every site
    When I run 'bin/ee site list --enabled --disabled --format=json'
    Then the command should succeed
    And the JSON array in STDOUT should contain an item with "site" set to "bh-site-command-40.test"
    And the JSON array in STDOUT should contain an item with "site" set to "bh-site-command-41.test"

  Scenario: An unsupported format is rejected
    When I run 'bin/ee site list --format=xml'
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'format'"
