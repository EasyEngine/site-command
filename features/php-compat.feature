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

  Scenario: --output refuses /dev/null and other paths that aren't regular files, before anything runs
    Given the site "bh-site-command-181.test" exists, created with "--type=php"
    And I link "{TMP}/null-link" to "/dev/null"
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --output=/dev/null'
    Then the command should fail
    And STDERR should contain "--output can't be in /dev: /dev/null"
    And /dev/null should still be the null device
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --output=/dev/stdout'
    Then the command should fail
    And STDERR should contain "--output can't be in /dev: /dev/stdout"
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --output={TMP}/null-link'
    Then the command should fail
    And STDERR should contain "--output is not a regular file: {TMP}/null-link"
    And /dev/null should still be the null device
    And the symlink "{TMP}/null-link" should exist
    And the docker container "ee-php-compat-bh-site-command-181.test" should not exist

  @wp
  Scenario: A --public-dir site whose record lost its public dir is checked where nginx serves it, from inside the site directory
    Given the compat-scanner image is available
    And the site "bh-site-command-185.test" exists, created with "--type=wp --public-dir=current"
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n * Version: 1.0\n */\n$f = create_function( '', 'return 1;' );\n" to the file "{SITES_DIR}/bh-site-command-185.test/app/htdocs/current/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-185.test/app/htdocs/current/wp-content/plugins/compat-fixture" readable by everyone
    # The record a user's server had: nginx still serves htdocs/current.
    And I set the "site_container_fs_path" field of site "bh-site-command-185.test" in the database to "/var/www/htdocs"
    When I run 'cd {SITES_DIR}/bh-site-command-185.test && {EE} site php-compat --php=8.4 --format=json'
    Then the command should succeed
    And STDOUT should match "/^\{.*\}\n?$/"
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "site" in STDOUT should be "bh-site-command-185.test"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    And STDERR should contain "EE's record of bh-site-command-185.test has /var/www/htdocs as its public directory, but nginx serves /var/www/htdocs/current"
    When I run 'cd {SITES_DIR}/bh-site-command-185.test/app/htdocs && {EE} site php-compat --php=8.4 --estimate --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And STDOUT should match "/"php_files":[1-9]/"
    When I run 'cd {SITES_DIR}/bh-site-command-185.test && {EE} site php-compat --php=8.4'
    Then the command should succeed
    And STDOUT should match "/^Not safe to upgrade bh-site-command-185\.test to PHP 8\.4: /"
    And STDOUT should contain "Compat Fixture 1.0 (plugin compat-fixture): 1 error"

  @wp
  Scenario: A nested --public-dir is checked from a directory inside the site
    Given the compat-scanner image is available
    And the site "bh-site-command-186.test" exists, created with "--type=wp --public-dir=/current/public/"
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n * Version: 1.0\n */\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-186.test/app/htdocs/current/public/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-186.test/app/htdocs/current/public/wp-content/plugins/compat-fixture" readable by everyone
    Then the "site_container_fs_path" field of site "bh-site-command-186.test" in the database should be "/var/www/htdocs/current/public"
    When I run 'cd {SITES_DIR}/bh-site-command-186.test/app/htdocs/current/public/wp-content && {EE} site php-compat --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    And STDERR should not contain "nginx serves"

  Scenario: A php site whose record lost its public dir is checked with a warning
    Given the compat-scanner image is available
    And the site "bh-site-command-187.test" exists, created with "--type=php --public-dir=public"
    And I write "<?php\n$f = create_function( '', 'return 1;' );\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-187.test/app/htdocs/public/legacy.php"
    And I make everything under "{SITES_DIR}/bh-site-command-187.test/app/htdocs/public" readable by everyone
    And I set the "site_container_fs_path" field of site "bh-site-command-187.test" in the database to "/var/www/htdocs"
    When I run 'cd {SITES_DIR}/bh-site-command-187.test && {EE} site php-compat --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "errors" in STDOUT should be "2"
    And STDERR should contain "but nginx serves /var/www/htdocs/public"

  @wp
  Scenario: A WP_CONTENT_DIR the check can't follow gives no verdict
    Given the site "bh-site-command-188.test" exists, created with "--type=wp"
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n */\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-188.test/app/htdocs/content/plugins/compat-fixture/compat-fixture.php"
    # Before wp-settings.php is loaded, where WordPress reads it.
    When I run 'sed -i "s#^require_once ABSPATH#define( \"WP_CONTENT_DIR\", __DIR__ . \"/htdocs/content\" );\nrequire_once ABSPATH#" {SITES_DIR}/bh-site-command-188.test/app/wp-config.php'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-188.test/app/wp-config.php" should contain "/htdocs/content"
    When I run 'bin/ee site php-compat bh-site-command-188.test --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "tool_error"
    And the JSON field "safe_to_upgrade" in STDOUT should be null
    And the JSON field "detail" in STDOUT should contain "WordPress loads plugins and themes from {SITES_DIR}/bh-site-command-188.test/app/htdocs/content (WP_CONTENT_DIR in {SITES_DIR}/bh-site-command-188.test/app/wp-config.php); this check can't scan that layout yet"
    And the docker container "ee-php-compat-bh-site-command-188.test" should not exist
    When I run 'bin/ee site php-compat bh-site-command-188.test --php=8.4'
    Then the command should succeed
    And STDOUT should match "/^The check of bh-site-command-188\.test for PHP 8\.4 didn't finish: WordPress loads plugins and themes from /"
    And STDOUT should not contain "Safe to upgrade"

  @wp
  Scenario: Without wp-content the message names the directory checked, and a release layout under htdocs is found
    Given the compat-scanner image is available
    And the site "bh-site-command-189.test" exists, created with "--type=wp"
    # Nothing at all to check: WordPress moved out of htdocs, and no wp-config.php.
    When I run 'mkdir {SITES_DIR}/bh-site-command-189.test/app/off && mv {SITES_DIR}/bh-site-command-189.test/app/htdocs/* {SITES_DIR}/bh-site-command-189.test/app/wp-config.php {SITES_DIR}/bh-site-command-189.test/app/off/'
    And I run 'cd {SITES_DIR}/bh-site-command-189.test && {EE} site php-compat --php=8.4'
    Then the command should succeed
    And STDOUT should contain "No PHP code found to check in bh-site-command-189.test. Looked in {SITES_DIR}/bh-site-command-189.test/app/htdocs: everything there except WordPress core, wp-content included. The check follows the directory nginx serves (root in {SITES_DIR}/bh-site-command-189.test/config/nginx/conf.d/main.conf)"
    When I run 'mv {SITES_DIR}/bh-site-command-189.test/app/off/wp-config.php {SITES_DIR}/bh-site-command-189.test/app/ && mv {SITES_DIR}/bh-site-command-189.test/app/off/* {SITES_DIR}/bh-site-command-189.test/app/htdocs/ && mv {SITES_DIR}/bh-site-command-189.test/app/htdocs/wp-content {SITES_DIR}/bh-site-command-189.test/app/htdocs/wp-content-off'
    # A CI/CD deploy: WordPress in releases/1, current links to it, wp-cli.yml points there, the record still says htdocs.
    When I run 'cd {SITES_DIR}/bh-site-command-189.test/app/htdocs && mkdir -p releases/1 && for f in *; do [ "$f" = releases ] || mv "$f" releases/1/; done && mv releases/1/wp-content-off releases/1/wp-content && ln -s /var/www/htdocs/releases/1 current && printf "path: current/\n" > wp-cli.yml'
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n */\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-189.test/app/htdocs/releases/1/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-189.test/app/htdocs/releases" readable by everyone
    And I run 'cd {SITES_DIR}/bh-site-command-189.test && {EE} site php-compat --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"

  Scenario: --estimate and --cancel take no value
    Given the site "bh-site-command-181.test" exists, created with "--type=php"
    When I run 'bin/ee site php-compat bh-site-command-181.test --php=8.4 --estimate=false'
    Then the command should fail
    And STDERR should contain "--estimate doesn't take a value."
    When I run 'bin/ee site php-compat bh-site-command-181.test --cancel=false'
    Then the command should fail
    And STDERR should contain "--cancel doesn't take a value."
    And the docker container "ee-php-compat-bh-site-command-181.test" should not exist

  Scenario: Deleting a site stops its running check and removes what php-compat kept for it
    Given the compat-scanner image is available
    And the site "bh-site-command-191.test" exists, created with "--type=php"
    And the directory "{SITES_DIR}/bh-site-command-191.test/app/htdocs/bulk" holds 5000 generated PHP files
    When I run 'bin/ee site php-compat bh-site-command-191.test --php=8.4 --estimate --format=json'
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/tmp/php-compat/.estimate-lock/bh-site-command-191.test" should exist
    When I start 'bin/ee site php-compat bh-site-command-191.test --php=8.4 --format=json' in the background
    And I wait up to 120 seconds until the command "docker inspect -f '{{.State.Running}}' ee-php-compat-bh-site-command-191.test | grep -qx true" succeeds
    And I run 'bin/ee site delete bh-site-command-191.test --yes'
    Then the command should succeed
    And the site "bh-site-command-191.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-191.test" should not exist
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-191.test" should not exist
    And the file "{EE_ROOT_DIR}/tmp/php-compat/.estimate-lock/bh-site-command-191.test" should not exist
    And the file "{EE_ROOT_DIR}/tmp/php-compat/.cancel-lock/bh-site-command-191.test" should not exist
    And the file "{EE_ROOT_DIR}/tmp/php-compat/.cancel/bh-site-command-191.test" should not exist
    When I wait up to 120 seconds for the background command to finish
    Then the JSON field "status" in STDOUT should be "cancelled"

  Scenario: A hangup doesn't stop a running check
    Given the compat-scanner image is available
    And the site "bh-site-command-183.test" exists, created with "--type=php"
    And the directory "{SITES_DIR}/bh-site-command-183.test/app/htdocs/bulk" holds 5000 generated PHP files
    When I start 'nohup bin/ee site php-compat bh-site-command-183.test --php=8.4 --format=json' in the background
    And I wait up to 120 seconds until the command "docker inspect -f '{{.State.Running}}' ee-php-compat-bh-site-command-183.test | grep -qx true" succeeds
    # [s] keeps pkill's own shell out of the match.
    And I run 'pkill -HUP -f "[s]ite php-compat bh-site-command-183.test --php=8.4 --format=json"'
    Then the command should succeed
    # A lost terminal hangs up the whole job, the docker client too.
    When I run 'pkill -HUP -f "[d]ocker run .*--name ee-php-compat-bh-site-command-183.test "'
    Then the command should succeed
    When I wait for the next second
    Then the background command should still be running
    When I wait up to 900 seconds for the background command to finish
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"

  @wp
  Scenario: Code mounted into the php container by a user compose file is checked too
    Given the compat-scanner image is available
    And the site "bh-site-command-190.test" exists, created with "--type=wp"
    And I write "/*\nTheme Name: Mounted Fixture\nVersion: 1.0\n*/\n" to the file "{SITES_DIR}/bh-site-command-190.test/theme-src/style.css"
    And I write "<?php\n$f = create_function( '', 'return 1;' );\n" to the file "{SITES_DIR}/bh-site-command-190.test/theme-src/functions.php"
    And I make everything under "{SITES_DIR}/bh-site-command-190.test/theme-src" readable by everyone
    And I write "services:\n  php:\n    volumes:\n      - ./theme-src:/var/www/htdocs/wp-content/themes/mounted-fixture\n" to the file "{SITES_DIR}/bh-site-command-190.test/user-docker-compose/mount.yml"
    When I run 'bin/ee site enable bh-site-command-190.test --refresh --force'
    Then the command should succeed
    When I run 'bin/ee site php-compat bh-site-command-190.test --php=8.4'
    Then the command should succeed
    And STDOUT should contain "Mounted Fixture 1.0 (theme mounted-fixture): 1 error"
    When I run 'bin/ee site disable bh-site-command-190.test'
    And I run 'bin/ee site php-compat bh-site-command-190.test --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "tool_error"
    And the JSON field "detail" in STDOUT should contain "user-docker-compose/mount.yml mounts code below /var/www, which this check can only see while the site runs"

  @wp
  Scenario: WordPress in its own directory is found through the index.php nginx serves
    Given the compat-scanner image is available
    And the site "bh-site-command-192.test" exists, created with "--type=wp"
    # Core and wp-content in htdocs/wp, loaded by htdocs/index.php; no wp-cli.yml.
    When I run 'cd {SITES_DIR}/bh-site-command-192.test/app/htdocs && mkdir wp && mv wp-admin wp-includes wp-content wp-*.php index.php license.txt readme.html xmlrpc.php wp/ && printf "<?php\ndefine( \"WP_USE_THEMES\", true );\nrequire __DIR__ . \"/wp/wp-blog-header.php\";\n" > index.php && chown -R 33:33 .'
    Then the command should succeed
    And I write "<?php\n/**\n * Plugin Name: Compat Fixture\n * Version: 1.0\n */\neach( $a );\n" to the file "{SITES_DIR}/bh-site-command-192.test/app/htdocs/wp/wp-content/plugins/compat-fixture/compat-fixture.php"
    And I make everything under "{SITES_DIR}/bh-site-command-192.test/app/htdocs/wp/wp-content/plugins/compat-fixture" readable by everyone
    When I run 'cd {SITES_DIR}/bh-site-command-192.test && {EE} site php-compat --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "false"
    # An index.php that loads WordPress from a place the check can't read gives no verdict.
    When I run 'printf "<?php\n\$d = __DIR__ . \"/wp\";\nrequire \$d . \"/wp-blog-header.php\";\n" > {SITES_DIR}/bh-site-command-192.test/app/htdocs/index.php'
    And I run 'bin/ee site php-compat bh-site-command-192.test --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "tool_error"
    And the JSON field "detail" in STDOUT should contain "can't resolve where htdocs/index.php loads WordPress (wp-blog-header.php) from"

  Scenario: An html site is refused
    Given the html site "bh-site-command-182.test" exists
    When I run 'bin/ee site php-compat bh-site-command-182.test --php=8.4'
    Then the command should fail
    And STDERR should contain "ee site php-compat supports wp and php sites; bh-site-command-182.test is a html site."
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-182.test" should not exist
    And the docker container "ee-php-compat-bh-site-command-182.test" should not exist

  # 9.9 fails in the dispatcher; 8 passes its loose comparison (8 == 8.0) and is caught by the command. PHP 7.4 also passes 8.4- through loosely.
  Scenario Outline: A bad --php value, a missing one, or a flag --cancel does not take is rejected
    Given the site "bh-site-command-181.test" exists, created with "--type=php"
    When I run 'bin/ee site php-compat bh-site-command-181.test <flags>'
    Then the command should fail
    And STDERR should contain "<message>"
    And the docker container "ee-php-compat-bh-site-command-181.test" should not exist

    Examples:
      | flags                | message                                   |
      | --php=9.9            | Invalid value specified for 'php'         |
      | --php=8              | Invalid --php value. Use one of: 5.6, 7.0 |
      | --php=8.4-           | Invalid                                   |
      | --php=abc            | Invalid value specified for 'php'         |
      |                      | missing --php parameter                   |
      | --estimate           | missing --php parameter                   |
      | --cancel --php=8.4   | --php doesn't apply to --cancel.          |
      | --cancel --estimate  | --estimate doesn't apply to --cancel.     |
      | --cancel --timeout=5 | --timeout doesn't apply to --cancel.      |

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

  # Deterministic: a scan killed before its cleanup stands in for a running one (the race with a live scan is unit- and live-tested).
  Scenario: --cancel with nothing running says so
    Given the site "bh-site-command-184.test" exists, created with "--type=php"
    When I run 'bin/ee site php-compat bh-site-command-184.test --cancel --format=json'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      {"v":1,"status":"not_running","cancel":true,"site":"bh-site-command-184.test"}
      """
    When I run 'bin/ee site php-compat bh-site-command-184.test --cancel'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      No check of bh-site-command-184.test is running.
      """
    And the docker container "ee-php-compat-bh-site-command-184.test" should not exist

  Scenario: --cancel stops the container of a check and removes its temporary directory
    Given the compat-scanner image is available
    And the site "bh-site-command-184.test" exists, created with "--type=php"
    And I write "<?php\nfunction bh_compat_ok() { return 1; }\n" to the file "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean/ok.php"
    And I make everything under "{SITES_DIR}/bh-site-command-184.test/app/htdocs/clean" readable by everyone
    And a killed php-compat scan of site "bh-site-command-184.test" left its temporary directory and a running container behind
    And the docker container "ee-php-compat-bh-site-command-184.test" should be running
    When I run 'bin/ee site php-compat bh-site-command-184.test --cancel --format=json'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      {"v":1,"status":"cancelled","cancel":true,"site":"bh-site-command-184.test"}
      """
    And the docker container "ee-php-compat-bh-site-command-184.test" should not exist
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-184.test" should not exist
    And the file "{EE_ROOT_DIR}/tmp/php-compat/.cancel/bh-site-command-184.test" should not exist
    When I run 'bin/ee site php-compat bh-site-command-184.test --cancel'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      No check of bh-site-command-184.test is running.
      """
    # The next check runs normally: no cancel request is left behind.
    When I run 'bin/ee site php-compat bh-site-command-184.test --php=8.4 --format=json'
    Then the command should succeed
    And the JSON field "status" in STDOUT should be "ok"
    And the JSON field "safe_to_upgrade" in STDOUT should be "true"

  Scenario: --cancel removes the stopped container and directory of a killed check
    Given the compat-scanner image is available
    And the site "bh-site-command-184.test" exists, created with "--type=php"
    And a killed php-compat scan of site "bh-site-command-184.test" left its temporary directory and container behind
    When I run 'bin/ee site php-compat bh-site-command-184.test --cancel'
    Then the command should succeed
    And STDOUT should be exactly:
      """
      No check of bh-site-command-184.test is running.
      """
    And the docker container "ee-php-compat-bh-site-command-184.test" should not exist
    And the directory "{EE_ROOT_DIR}/tmp/php-compat/bh-site-command-184.test" should not exist

  Scenario: --cancel of a site that doesn't exist is refused
    When I run 'bin/ee site php-compat bh-site-command-missing.test --cancel'
    Then the command should fail
    And STDERR should contain "Could not find the site you wish to run site php-compat command on."
