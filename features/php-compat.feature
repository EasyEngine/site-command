Feature: Check the PHP compatibility of a site's code
  `ee site php-compat` scans wp and php sites with the easyengine/compat-scanner image. Every scan scenario needs core's img-versions.json to pin that image, and the image locally or pullable ("the compat-scanner image is available").
  Fixtures are written as root and made world-readable, since the scanner reads the code as uid 33. Commands whose output holds findings run "without logging it" where ee.log is checked, because "I run" copies a command's output into ee.log.

  @wp
  Scenario: The JSON summary of a WordPress site
    Given "ee" is installed
    And the compat-scanner image is available
    And the site "bh-site-command-180.test" exists, created with "--type=wp"
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n * Version: 1.0\n */\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-180.test/app/htdocs/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-180.test/app/htdocs/wp-content/plugins/compat-fixture" readable by everyone
    When I run 'bin/ee site php-compat bh-site-command-180.test --php=8.4 --format=json'
    Then the command should succeed
    And STDOUT should be valid JSON
    And STDOUT should match "/^\{.*\}\n?$/"
    And the JSON field "v" in STDOUT should be "1"
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "site" in STDOUT should be "bh-site-command-180.test"
    And the JSON field "target" in STDOUT should be "8.4"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    And STDOUT should match "/"errors":[1-9]/"
    And the JSON field "tool.image" in STDOUT should contain "easyengine/compat-scanner:"
    And STDOUT should not contain "findings"
    And STDOUT should not contain "create_function"
    And STDOUT should not contain "report"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-180.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-180.test" should not exist

  @wp
  Scenario: The table starts with the verdict line and ends with the static-analysis line
    Given "ee" is installed
    And the compat-scanner image is available
    And the site "bh-site-command-180.test" exists, created with "--type=wp"
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n * Version: 1.0\n */\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-180.test/app/htdocs/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-180.test/app/htdocs/wp-content/plugins/compat-fixture" readable by everyone
    When I run 'bin/ee site php-compat bh-site-command-180.test --php=8.4'
    Then the command should succeed
    And STDOUT should match "/^Not safe to upgrade bh-site-command-180\.test to PHP 8\.4: \d+ errors? in \d+ components?\. \d+ warnings?\. \d+ files? checked\.\n/"
    And STDOUT should contain "Compat Fixture 1.0 (plugin compat-fixture): 2 errors, "
    And STDOUT should contain "  compat-fixture.php:6"
    And STDOUT should contain "    PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved"
    And STDOUT should contain "  compat-fixture.php:7"
    And STDOUT should contain "    PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved"
    And STDOUT should match "/\nThis is static analysis with PHPCompatibility [^\n]+\. Its coverage isn't complete and it can't see breakage that only shows at runtime\.\n$/"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-180.test" should not exist

  Scenario: The JSON summary of a php site
    Given the compat-scanner image is available
    And the site "bh-site-command-181.test" exists, created with "--type=php"
    And I write "<?php\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy/legacy.php"
    And I make everything under "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy" readable by everyone
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --format=json'
    Then the command should succeed
    And STDOUT should be valid JSON
    And STDOUT should match "/^\{.*\}\n?$/"
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "site" in STDOUT should be "bh-site-command-181.test"
    And the JSON field "target" in STDOUT should be "8.4"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    And the JSON field "errors" in STDOUT should be "2"
    And the JSON field "components_with_errors" in STDOUT should be "1"
    And STDOUT should not contain "findings"
    And STDOUT should not contain "report"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-181.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-181.test" should not exist

  Scenario: The table of a php site without errors says it is safe
    Given the compat-scanner image is available
    And the site "bh-site-command-184.test" exists, created with "--type=php"
    And I write "<?php\nfunction bh_compat_ok() { return 1; }\n" to the file "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean/ok.php"
    And I make everything under "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean" readable by everyone
    When I run 'bin/ee site php-compat bh-site-command-184.test --php=8.4'
    Then the command should succeed
    And STDOUT should match "/^Safe to upgrade bh-site-command-184\.test to PHP 8\.4: 0 errors\. \d+ warnings?\. \d+ files? checked\.\n/"
    And STDOUT should match "/\nThis is static analysis with PHPCompatibility [^\n]+\. Its coverage isn't complete and it can't see breakage that only shows at runtime\.\n$/"

  Scenario: --output writes a root-owned 0600 report that matches the summary
    Given the compat-scanner image is available
    And the site "bh-site-command-181.test" exists, created with "--type=php"
    And I write "<?php\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy/legacy.php"
    And I make everything under "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy" readable by everyone
    # A file already at the path is replaced.
    And I write "stale" to the file "{TMP}/report.json"
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --format=json --output={TMP}/report.json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    And the JSON field "report" in STDOUT should be "{TMP}/report.json"
    And the JSON field "report_bytes" in STDOUT should be the size of the file "{TMP}/report.json"
    And the file "{TMP}/report.json" should be owned by uid 0 with mode 0600
    And the JSON file "{TMP}/report.json" should hold every field of the STDOUT JSON except "report,report_bytes"
    And the file "{TMP}/report.json" should not contain "stale"
    And the file "{TMP}/report.json" should contain "PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved"
    And STDOUT should not contain "create_function"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-181.test" should not exist

  Scenario: ee.log holds no finding text after a check with findings
    Given the compat-scanner image is available
    And the site "bh-site-command-181.test" exists, created with "--type=php"
    And I write "<?php\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy/legacy.php"
    And I make everything under "{SITES_DIR}/bh-site-command-181.test/app/htdocs/legacy" readable by everyone
    When I note the size of the file "{EE_ROOT_DIR}/logs/ee.log"
    And I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4' without logging it
    Then the command should succeed
    And STDOUT should contain "PHPCompatibility.FunctionUse.RemovedFunctions.create_functionDeprecatedRemoved"
    And STDOUT should contain "legacy.php:2"
    # Proves the run did log to ee.log, so the checks below can't pass on an empty log.
    And what was appended to the file "{EE_ROOT_DIR}/logs/ee.log" since its size was noted should contain "php-compat scan of bh-site-command-181.test for PHP 8.4: status ok"
    And what was appended to the file "{EE_ROOT_DIR}/logs/ee.log" since its size was noted should not contain "create_function"
    And what was appended to the file "{EE_ROOT_DIR}/logs/ee.log" since its size was noted should not contain "RemovedFunctions"
    And what was appended to the file "{EE_ROOT_DIR}/logs/ee.log" since its size was noted should not contain "legacy.php"

  Scenario: An html site is refused
    Given the html site "bh-site-command-182.test" exists
    When I run 'bin/ee site php-compat bh-site-command-182.test --php=8.4'
    Then the command should fail
    And STDERR should contain "ee site php-compat supports wp and php sites; bh-site-command-182.test is a html site."
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-182.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-182.test" should not exist

  # 9.9 fails in the dispatcher; 8 passes its loose comparison (8 == 8.0) and is caught by the command. PHP 7.4 also passes 8.4- through loosely.
  Scenario Outline: A bad --php value is rejected
    Given the site "bh-site-command-181.test" exists, created with "--type=php"
    When I run 'bin/ee site php-compat bh-site-command-181.test <flags>'
    Then the command should fail
    And STDERR should contain "<message>"
    And the docker container "ee-php-compat-bh-site-command-181.test" should not exist

    Examples:
      | flags      | message                                   |
      | --php=9.9  | Invalid value specified for 'php'         |
      | --php=8    | Invalid --php value. Use one of: 5.6, 7.0 |
      | --php=8.4- | Invalid                                   |
      | --php=abc  | Invalid value specified for 'php'         |
      |            | missing --php parameter                   |

  # The 5,000 generated files (about 30 MB) keep the first scan running for tens of seconds, well past the second command.
  Scenario: A second check while one of the same site runs reports busy
    Given the compat-scanner image is available
    And the site "bh-site-command-183.test" exists, created with "--type=php"
    And the directory "{SITES_DIR}/bh-site-command-183.test/app/htdocs/bulk" holds 5000 generated PHP files
    When I start 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --format=json' in the background
    And I wait up to 120 seconds until the command "docker inspect -f '{{.State.Running}}' ee-php-compat-bh-site-command-183.test | grep -qx true" succeeds
    And I run 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --format=json'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      {"v":1,"status":"busy","site":"bh-site-command-183.test","target":"8.4","safe_to_upgrade":null}
      """
    When I run 'bin/ee site php-compat bh-site-command-183.test --php=8.4'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      A check of bh-site-command-183.test is already running.
      """
    When I wait up to 900 seconds for the background command to finish
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-183.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-183.test" should not exist

  Scenario: --estimate prints its JSON while a check of the same site runs
    Given the compat-scanner image is available
    And the site "bh-site-command-183.test" exists, created with "--type=php"
    And the directory "{SITES_DIR}/bh-site-command-183.test/app/htdocs/bulk" holds 5000 generated PHP files
    When I start 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --format=json' in the background
    And I wait up to 120 seconds until the command "docker inspect -f '{{.State.Running}}' ee-php-compat-bh-site-command-183.test | grep -qx true" succeeds
    And I run 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --estimate --format=json'
    Then the command should succeed
    And STDOUT should be valid JSON
    And STDOUT should match "/^\{.*\}\n?$/"
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "estimate" in STDOUT should be "true"
    And the JSON field "site" in STDOUT should be "bh-site-command-183.test"
    And the JSON field "target" in STDOUT should be "8.4"
    And STDOUT should match "/"php_files":[1-9][0-9]{3}/"
    And STDOUT should match "/"estimated_seconds":[1-9]/"
    And the background command should still be running
    When I run 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --estimate'
    Then the command should succeed
    And STDOUT should match "/^A check of bh-site-command-183\.test for PHP 8\.4 should take about .+ This is an estimate\.\n$/"
    When I wait up to 900 seconds for the background command to finish
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-183.test" should not exist

  Scenario: No temporary directory or container is left behind, also when the check times out
    Given the compat-scanner image is available
    And the site "bh-site-command-183.test" exists, created with "--type=php"
    And the directory "{SITES_DIR}/bh-site-command-183.test/app/htdocs/bulk" holds 5000 generated PHP files
    When I run 'bin/ee site php-compat bh-site-command-183.test --php=8.4 --format=json --timeout=1'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "tool_error"
    And the JSON field "safe_to_upgrade" in STDOUT should be null
    And the JSON field "detail" in STDOUT should contain "timed out after 1 s"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-183.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-183.test" should not exist

  Scenario: A leftover of a killed check is removed by the next check
    Given the compat-scanner image is available
    And the site "bh-site-command-184.test" exists, created with "--type=php"
    And I write "<?php\nfunction bh_compat_ok() { return 1; }\n" to the file "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean/ok.php"
    And I make everything under "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean" readable by everyone
    And a killed php-compat scan of site "bh-site-command-184.test" left its temporary directory and container behind
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-184.test" should exist
    And the docker container "ee-php-compat-bh-site-command-184.test" should exist
    When I run 'bin/ee site php-compat bh-site-command-184.test --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "true"
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-184.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-184.test" should not exist
