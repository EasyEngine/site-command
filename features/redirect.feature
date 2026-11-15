Feature: Site Redirection
  www <-> non-www redirects in nginx-proxy. EE only writes the redirect when the other name resolves to this host, so each scenario adds (and afterwards removes) the needed hosts entry.

  Scenario: no_www-no_ssl redirection works properly
    Given the hosts file contains an entry for "www.bh-site-command-120.test"
    And the site "bh-site-command-120.test" does not exist
    When I run 'bin/ee site create bh-site-command-120.test'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-120.test-redirect.conf" should contain "server_name  www.bh-site-command-120.test;"
    When I wait up to 60 seconds for "http://www.bh-site-command-120.test/" to return 301
    Then the response header "Location" should be "http://bh-site-command-120.test/"

  Scenario: www-no_ssl redirection works properly
    Given the hosts file contains an entry for "bh-site-command-121.test"
    And the site "www.bh-site-command-121.test" does not exist
    When I run 'bin/ee site create www.bh-site-command-121.test'
    Then the command should succeed
    When I wait up to 60 seconds for "http://bh-site-command-121.test/" to return 301
    Then the response header "Location" should be "http://www.bh-site-command-121.test/"

  Scenario: No redirect is written when the www name does not resolve here
    Given the site "bh-site-command-122.test" does not exist
    And the hosts file should not contain an entry for "www.bh-site-command-122.test"
    When I run 'bin/ee site create bh-site-command-122.test'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-122.test-redirect.conf" should not exist

  Scenario: Disable removes the redirect and enable restores it
    Given the hosts file contains an entry for "www.bh-site-command-123.test"
    And the site "bh-site-command-123.test" does not exist
    When I run 'bin/ee site create bh-site-command-123.test'
    Then the file "{PROXY_DIR}/conf.d/bh-site-command-123.test-redirect.conf" should exist
    When I run 'bin/ee site disable bh-site-command-123.test'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-123.test-redirect.conf" should not exist
    When I run 'bin/ee site enable bh-site-command-123.test'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-123.test-redirect.conf" should exist
    When I wait up to 60 seconds for "http://www.bh-site-command-123.test/" to return 301
    Then the response header "Location" should be "http://bh-site-command-123.test/"

  Scenario: Delete removes the redirect
    Given the hosts file contains an entry for "www.bh-site-command-124.test"
    And the site "bh-site-command-124.test" does not exist
    When I run 'bin/ee site create bh-site-command-124.test'
    Then the file "{PROXY_DIR}/conf.d/bh-site-command-124.test-redirect.conf" should exist
    When I run 'bin/ee site delete bh-site-command-124.test --yes'
    Then the command should succeed
    And the file "{PROXY_DIR}/conf.d/bh-site-command-124.test-redirect.conf" should not exist

  Scenario: HTTPS www redirect on a self-signed site
    Given the hosts file contains an entry for "www.bh-site-command-126.test"
    And the site "bh-site-command-126.test" does not exist
    When I run 'bin/ee site create bh-site-command-126.test --ssl=self'
    Then the command should succeed
    When I wait up to 60 seconds for "https://www.bh-site-command-126.test/" to return 301
    Then the response header "Location" should be "https://bh-site-command-126.test/"
    When I send a GET request to "http://www.bh-site-command-126.test/"
    Then the response code should be 301
    And the response header "Location" should be "https://bh-site-command-126.test/"
