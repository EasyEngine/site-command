Feature: Clean site caches
  `ee site clean` on html sites: object/page caches never exist for html, proxy cache only when enabled.

  Scenario: Cleaning all caches of a plain html site fails
    Given the html site "bh-site-command-88.test" exists
    When I run 'bin/ee site clean bh-site-command-88.test'
    Then the command should fail
    And STDERR should contain "Site object cache is not enabled. Site page cache is not enabled."

  Scenario: Cleaning the object cache of an html site fails
    Given the html site "bh-site-command-88.test" exists
    When I run 'bin/ee site clean bh-site-command-88.test --object'
    Then the command should fail
    And STDERR should contain "Site object cache is not enabled."
    And STDERR should not contain "page cache"

  Scenario: Cleaning the page cache of an html site fails
    Given the html site "bh-site-command-88.test" exists
    When I run 'bin/ee site clean bh-site-command-88.test --page'
    Then the command should fail
    And STDERR should contain "Site page cache is not enabled."
    And STDERR should not contain "object cache"

  Scenario: Cleaning the proxy cache when it is off fails
    Given the html site "bh-site-command-88.test" exists
    When I run 'bin/ee site clean bh-site-command-88.test --proxy'
    Then the command should fail
    And STDERR should contain "Proxy cache is not enabled on site."

  # Regression check (fixed in v4.13.2 by site-command 276d118): the proxy purge used `docker exec -it` and ignored the exit code (no TTY: failed silently; nested ptys: could hang), hence the timeout.
  @destructive
  Scenario: Cleaning the proxy cache empties it
    Given the html site "bh-site-command-89.test" exists
    When I run 'bin/ee site update bh-site-command-89.test --proxy-cache=on'
    Then the command should succeed
    When I wait up to 60 seconds for "http://bh-site-command-89.test/" to return 200
    And I send a GET request to "http://bh-site-command-89.test/"
    And I wait up to 30 seconds until the command "docker exec services_global-nginx-proxy_1 sh -c 'find /var/cache/nginx/bh-site-command-89.test -type f | grep -q .'" succeeds
    And I run 'bin/ee site clean bh-site-command-89.test --proxy' with a timeout of 120 seconds
    Then the command should succeed
    And STDOUT should contain "Proxy cache cleared for bh-site-command-89.test"
    When I run "docker exec services_global-nginx-proxy_1 sh -c 'find /var/cache/nginx/bh-site-command-89.test -type f | wc -l'"
    Then STDOUT should be exactly:
      """
      0
      """
    And the docker container "services_global-nginx-proxy_1" should be running
