@destructive
Feature: Proxy cache
  `ee site update --proxy-cache` writes nginx-proxy config and restarts the GLOBAL nginx-proxy (all sites blip), hence @destructive.
  Every scenario ends with the proxy running.

  Scenario: Enable the proxy cache with defaults
    Given the html site "bh-site-command-80.test" exists
    When I run 'bin/ee site update bh-site-command-80.test --proxy-cache=on'
    Then the command should succeed
    And STDOUT should contain "Enabling proxy cache for: bh-site-command-80.test"
    And STDOUT should contain "Proxy cache update on site bh-site-command-80.test completed."
    And the "proxy_cache" field of site "bh-site-command-80.test" in the database should be "on"
    And the file "{PROXY_DIR}/conf.d/bh-site-command-80.test.conf" should contain "keys_zone=bh-site-command-80-test:10m max_size=256m"
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-80.test_location" should contain "proxy_cache_valid 200 301 302 404 1s;"
    And the docker container "services_global-nginx-proxy_1" should be running
    When I wait up to 60 seconds for "http://bh-site-command-80.test/" to return 200
    Then the response should have the header "X-Proxy-Cache"

  Scenario: Enabling the proxy cache twice fails
    Given the html site "bh-site-command-81.test" exists
    When I run 'bin/ee site update bh-site-command-81.test --proxy-cache=on'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-81.test --proxy-cache=on'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-81.test already has proxy cache: on"

  Scenario: Enable the proxy cache with custom sizes and time
    Given the html site "bh-site-command-82.test" exists
    When I run 'bin/ee site update bh-site-command-82.test --proxy-cache=on --proxy-cache-max-size=1g --proxy-cache-max-time=30s --proxy-cache-key-zone-size=20m'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-82.test.conf" should contain "keys_zone=bh-site-command-82-test:20m max_size=1g"
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-82.test_location" should contain "proxy_cache_valid 200 301 302 404 30s;"
    When I wait up to 60 seconds for "http://bh-site-command-82.test/" to return 200

  Scenario: Invalid proxy cache values fall back to the defaults with warnings
    Given the html site "bh-site-command-83.test" exists
    When I run 'bin/ee site update bh-site-command-83.test --proxy-cache=on --proxy-cache-max-size=big --proxy-cache-max-time=forever --proxy-cache-key-zone-size=1g'
    Then the command should succeed
    And STDERR should contain "Wrong value `big` supplied to param: `proxy-cache-max-size`, replacing it with default:256m"
    And STDERR should contain "Wrong value `forever` supplied to param: `proxy-cache-max-time`, replacing it with default:1s"
    And STDERR should contain "Wrong value `1g` supplied to param: `proxy-cache-key-zone-size`, replacing it with default:10m"
    And the file "{PROXY_DIR}/conf.d/bh-site-command-83.test.conf" should contain "keys_zone=bh-site-command-83-test:10m max_size=256m"
    When I wait up to 60 seconds for "http://bh-site-command-83.test/" to return 200

  Scenario: Disable the proxy cache
    Given the html site "bh-site-command-84.test" exists
    When I run 'bin/ee site update bh-site-command-84.test --proxy-cache=on'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-84.test --proxy-cache=off'
    Then the command should succeed
    And STDOUT should contain "Disabling proxy cache for: bh-site-command-84.test"
    And the "proxy_cache" field of site "bh-site-command-84.test" in the database should be "off"
    And the file "{PROXY_DIR}/conf.d/bh-site-command-84.test.conf" should not exist
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-84.test_location" should not exist
    When I wait up to 60 seconds for "http://bh-site-command-84.test/" to return 200
    Then the response should not have the header "X-Proxy-Cache"

  Scenario: Disabling a proxy cache that is off fails
    Given the html site "bh-site-command-85.test" exists
    When I run 'bin/ee site update bh-site-command-85.test --proxy-cache=off'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-85.test already has proxy cache: off"

  Scenario: Alias domains get proxy cache locations, also when added later
    Given the site "bh-site-command-86.test" exists, created with "--type=html --alias-domains=bh-site-command-86-a.test"
    When I run 'bin/ee site update bh-site-command-86.test --proxy-cache=on'
    Then the command should succeed
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-86-a.test_location" should exist
    When I run 'bin/ee site update bh-site-command-86.test --add-alias-domains=bh-site-command-86-b.test'
    Then the command should succeed
    And STDOUT should contain "As proxy cache is enabled on this site, updating config to enable it in newly added alias domains."
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-86-b.test_location" should exist
    And the docker container "services_global-nginx-proxy_1" should be running

  Scenario: Deleting an alias removes its proxy cache location
    Given the site "bh-site-command-162.test" exists, created with "--type=html --alias-domains=bh-site-command-162-a.test,bh-site-command-162-b.test"
    When I run 'bin/ee site update bh-site-command-162.test --proxy-cache=on'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-162.test --delete-alias-domains=bh-site-command-162-a.test'
    Then the command should succeed
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-162-a.test_location" should not exist
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-162-b.test_location" should exist
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-162.test_location" should exist

  # Regression check (fixed in v4.13.1, EasyEngine/site-command#505): a failed nginx -t during the alias sync used to remove the cache zone but keep the locations using it, so nginx never passed -t again and could not start after a restart.
  # The broken include is removed right after the update, before any assertion can fail.
  Scenario: A failed proxy cache alias sync leaves a config nginx can load
    Given the site "bh-site-command-163.test" exists, created with "--type=html --alias-domains=bh-site-command-163-a.test"
    When I run 'bin/ee site update bh-site-command-163.test --proxy-cache=on'
    Then the command should succeed
    When I write "bh_broken_directive;" to the file "{PROXY_DIR}/conf.d/bh-site-command-163-broken.conf"
    And I run 'bin/ee site update bh-site-command-163.test --add-alias-domains=bh-site-command-163-b.test'
    And I remove the file "{PROXY_DIR}/conf.d/bh-site-command-163-broken.conf"
    Then the command should succeed
    And STDERR should contain "Could not enable proxy cache on bh-site-command-163.test, nginx config test failed. The previous proxy config was restored."
    And the file "{PROXY_DIR}/conf.d/bh-site-command-163.test.conf" should exist
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-163-b.test_location" should not exist
    When I run 'docker exec services_global-nginx-proxy_1 nginx -t'
    Then the command should succeed
    When I run 'docker restart services_global-nginx-proxy_1'
    Then the command should succeed
    When I wait up to 60 seconds for "http://bh-site-command-163.test/" to return 200
    Then the docker container "services_global-nginx-proxy_1" should be running

  Scenario: Deleting a proxy-cached site removes its proxy config
    Given the html site "bh-site-command-87.test" exists
    When I run 'bin/ee site update bh-site-command-87.test --proxy-cache=on'
    Then the command should succeed
    When I run 'bin/ee site delete bh-site-command-87.test --yes'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-87.test.conf" should not exist
    And the file "{PROXY_DIR}/vhost.d/bh-site-command-87.test_location" should not exist
    And the docker container "services_global-nginx-proxy_1" should be running
