Feature: Enable, disable and refresh sites
  `ee site disable` stops and removes the containers, `ee site enable` brings them back, `--refresh`/`refresh` regenerate docker-compose.yml.

  Scenario: Disable a site
    Given the html site "bh-site-command-50.test" exists
    When I run 'bin/ee site disable bh-site-command-50.test'
    Then the command should succeed
    And STDOUT should contain "Disabling site bh-site-command-50.test."
    And STDOUT should contain "Site bh-site-command-50.test disabled."
    And the site "bh-site-command-50.test" should be disabled
    And the site "bh-site-command-50.test" should have no containers
    And the site "bh-site-command-50.test" should have 3 docker volumes
    And the directory "{SITES_DIR}/bh-site-command-50.test/app/htdocs" should exist
    When I wait up to 60 seconds for "http://bh-site-command-50.test/" to not return 200
    Then the response code should not be 200

  Scenario: Disabling a disabled site is harmless
    Given the html site "bh-site-command-50.test" exists
    And the site "bh-site-command-50.test" is disabled
    When I run 'bin/ee site disable bh-site-command-50.test'
    Then the command should succeed
    And STDOUT should contain "Site bh-site-command-50.test disabled."
    And the site "bh-site-command-50.test" should be disabled

  Scenario Outline: Subcommands that need a running site refuse a disabled one
    Given the html site "bh-site-command-51.test" exists
    And the site "bh-site-command-51.test" is disabled
    When I run 'bin/ee site <command> bh-site-command-51.test <flags>'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-51.test is not enabled. Use `ee site enable bh-site-command-51.test` to enable it."

    Examples:
      | command | flags        |
      | restart |              |
      | reload  |              |
      | update  | --ssl=self   |
      | refresh |              |
      | share   |              |
      | backup  |              |
      | restore |              |

  Scenario: Enable a disabled site
    Given the html site "bh-site-command-52.test" exists
    And the site "bh-site-command-52.test" is disabled
    When I run 'bin/ee site enable bh-site-command-52.test'
    Then the command should succeed
    And STDOUT should contain "Enabling site bh-site-command-52.test."
    And STDOUT should contain "Site bh-site-command-52.test enabled."
    And STDOUT should contain "Post enable configurations complete."
    And the site "bh-site-command-52.test" should be enabled
    And the service "nginx" of site "bh-site-command-52.test" should be running
    When I wait up to 60 seconds for "http://bh-site-command-52.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"

  Scenario: Enabling an enabled site fails
    Given the html site "bh-site-command-52.test" exists
    When I run 'bin/ee site enable bh-site-command-52.test'
    Then the command should fail
    And STDERR should contain "bh-site-command-52.test is already enabled!"

  Scenario: Force-enable an enabled site
    Given the html site "bh-site-command-52.test" exists
    When I run 'bin/ee site enable bh-site-command-52.test --force'
    Then the command should succeed
    And STDOUT should contain "Site bh-site-command-52.test enabled."
    And the service "nginx" of site "bh-site-command-52.test" should be running

  Scenario: Enable with --refresh regenerates docker-compose.yml
    Given the html site "bh-site-command-53.test" exists
    When I append "# bh-site-command-marker" to the file "{SITES_DIR}/bh-site-command-53.test/docker-compose.yml"
    And I run 'bin/ee site enable bh-site-command-53.test --refresh'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-53.test/docker-compose.yml" should not contain "# bh-site-command-marker"
    And the file "{SITES_DIR}/bh-site-command-53.test/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"
    And the service "nginx" of site "bh-site-command-53.test" should be running
    When I wait up to 60 seconds for "http://bh-site-command-53.test/" to return 200

  Scenario: refresh is a shortcut for enable --refresh
    Given the html site "bh-site-command-53.test" exists
    When I append "# bh-site-command-marker" to the file "{SITES_DIR}/bh-site-command-53.test/docker-compose.yml"
    And I run 'bin/ee site refresh bh-site-command-53.test'
    Then the command should succeed
    And STDOUT should contain "Site bh-site-command-53.test enabled."
    And the file "{SITES_DIR}/bh-site-command-53.test/docker-compose.yml" should not contain "# bh-site-command-marker"
    When I wait up to 60 seconds for "http://bh-site-command-53.test/" to return 200

  Scenario: Enable with verification of the global services
    Given the html site "bh-site-command-54.test" exists
    And the site "bh-site-command-54.test" is disabled
    When I run 'bin/ee site enable bh-site-command-54.test --verify'
    Then the command should succeed
    And the site "bh-site-command-54.test" should be enabled
    And the docker container "services_global-nginx-proxy_1" should be running
    When I wait up to 60 seconds for "http://bh-site-command-54.test/" to return 200
