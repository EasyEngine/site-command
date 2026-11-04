Feature: Restart and reload site services
  `ee site restart` recreates nothing but restarts containers after `nginx -t`; `ee site reload` reloads nginx in place.

  Scenario Outline: Restart the site containers
    Given the html site "bh-site-command-56.test" exists
    When I note the start time of service "nginx" of site "bh-site-command-56.test"
    And I run 'bin/ee site restart bh-site-command-56.test <flags>'
    Then the command should succeed
    And STDOUT should contain "Restarting nginx"
    And the service "nginx" of site "bh-site-command-56.test" should have been restarted
    When I wait up to 60 seconds for "http://bh-site-command-56.test/" to return 200

    Examples:
      | flags   |
      |         |
      | --nginx |
      | --all   |

  Scenario: Restart rejects services the html type does not have
    Given the html site "bh-site-command-56.test" exists
    When I run 'bin/ee site restart bh-site-command-56.test --php'
    Then the command should fail
    And STDERR should contain "unknown --php parameter"

  Scenario Outline: Reload nginx without restarting the container
    Given the html site "bh-site-command-56.test" exists
    When I note the start time of service "nginx" of site "bh-site-command-56.test"
    And I run 'bin/ee site reload bh-site-command-56.test <flags>'
    Then the command should succeed
    And STDOUT should contain "Reloading nginx"
    And the service "nginx" of site "bh-site-command-56.test" should not have been restarted
    When I send a GET request to "http://bh-site-command-56.test/"
    Then the response code should be 200

    Examples:
      | flags   |
      |         |
      | --nginx |
      | --all   |

  Scenario: Restart refuses a broken nginx config and keeps the site serving
    Given the html site "bh-site-command-57.test" exists
    And I write "# Custom Nginx configuration can be put here\nbh_invalid_directive on;\n" to the file "{SITES_DIR}/bh-site-command-57.test/config/nginx/custom/user.conf"
    When I note the start time of service "nginx" of site "bh-site-command-57.test"
    And I run 'bin/ee site restart bh-site-command-57.test --nginx'
    Then the command should fail
    And STDERR should contain "Nginx test failed"
    And the service "nginx" of site "bh-site-command-57.test" should not have been restarted
    When I send a GET request to "http://bh-site-command-57.test/"
    Then the response code should be 200
    When I write "# Custom Nginx configuration can be put here\n" to the file "{SITES_DIR}/bh-site-command-57.test/config/nginx/custom/user.conf"
    And I run 'bin/ee site restart bh-site-command-57.test --nginx'
    Then the command should succeed

  Scenario: Custom nginx config is applied on reload
    Given the html site "bh-site-command-58.test" exists
    When I write "location = /bh-probe { return 200 'bh-site-command-probe'; }\n" to the file "{SITES_DIR}/bh-site-command-58.test/config/nginx/custom/user.conf"
    And I run 'bin/ee site reload bh-site-command-58.test'
    Then the command should succeed
    When I wait up to 30 seconds for "http://bh-site-command-58.test/bh-probe" to return 200
    Then the response body should contain "bh-site-command-probe"
    When I write "# Custom Nginx configuration can be put here\n" to the file "{SITES_DIR}/bh-site-command-58.test/config/nginx/custom/user.conf"
    And I run 'bin/ee site reload bh-site-command-58.test'
    Then the command should succeed
