Feature: Delete sites
  `ee site delete` removes containers, volumes, network, files, hosts entry, proxy config and the DB row.

  Scenario: Delete an html site and everything that belongs to it
    Given the html site "bh-site-command-30.test" exists
    When I run 'bin/ee site delete bh-site-command-30.test --yes'
    Then the command should succeed
    And STDOUT should contain "Site bh-site-command-30.test deleted."
    And STDERR should be empty
    And the site "bh-site-command-30.test" should not exist
    And The 'bh-site-command-30.test' db entry should be removed
    And The 'bh-site-command-30.test' webroot should be removed
    And Following containers of site 'bh-site-command-30.test' should be removed:
      | container |
      | nginx     |
    And the site "bh-site-command-30.test" should have no containers
    And the site "bh-site-command-30.test" should have 0 docker volumes
    And the docker network "bh-site-command-30.test" should not exist
    And the hosts file should not contain an entry for "bh-site-command-30.test"
    And the file "{PROXY_DIR}/conf.d/bh-site-command-30.test-redirect.conf" should not exist
    When I run 'bin/ee site delete bh-site-command-30.test --yes'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-30.test does not exist."

  Scenario: Answering no at the prompt keeps the site
    Given the html site "bh-site-command-31.test" exists
    When I run 'echo n | bin/ee site delete bh-site-command-31.test'
    Then the command should succeed
    And STDOUT should contain "Are you sure you want to delete bh-site-command-31.test? [y/n]"
    And the site "bh-site-command-31.test" should exist
    And the service "nginx" of site "bh-site-command-31.test" should be running

  Scenario: Answering yes at the prompt deletes the site
    Given the html site "bh-site-command-31.test" exists
    When I run 'echo y | bin/ee site delete bh-site-command-31.test'
    Then the command should succeed
    And STDOUT should contain "Site bh-site-command-31.test deleted."
    And the site "bh-site-command-31.test" should not exist

  Scenario: A disabled site can be deleted
    Given the html site "bh-site-command-32.test" exists
    And the site "bh-site-command-32.test" is disabled
    When I run 'bin/ee site delete bh-site-command-32.test --yes'
    Then the command should succeed
    And the site "bh-site-command-32.test" should not exist
    And the directory "{SITES_DIR}/bh-site-command-32.test" should not exist
    And the site "bh-site-command-32.test" should have 0 docker volumes

  Scenario: Deleting a self-signed site removes its certificate
    Given the site "bh-site-command-33.test" exists, created with "--type=html --ssl=self"
    And the file "{PROXY_DIR}/certs/bh-site-command-33.test.crt" should exist
    When I run 'bin/ee site delete bh-site-command-33.test --yes'
    Then the command should succeed
    And STDOUT should contain "Removing ssl certs and other config files."
    And the file "{PROXY_DIR}/certs/bh-site-command-33.test.crt" should not exist
    And the file "{PROXY_DIR}/certs/bh-site-command-33.test.key" should not exist

  # Delete removes the site's certificate files even when site_ssl is empty (SSL turned off or lost), since nginx-proxy keeps matching them.
  Scenario: Deleting a site without SSL removes a leftover certificate
    Given the html site "bh-site-command-161.test" exists
    When I write "leftover" to the file "{PROXY_DIR}/certs/bh-site-command-161.test.crt"
    And I write "leftover" to the file "{PROXY_DIR}/certs/bh-site-command-161.test.key"
    And I run 'bin/ee site delete bh-site-command-161.test --yes'
    Then the command should succeed
    And the file "{PROXY_DIR}/certs/bh-site-command-161.test.crt" should not exist
    And the file "{PROXY_DIR}/certs/bh-site-command-161.test.key" should not exist

  Scenario: Deleting a site frees its alias domains
    Given the site "bh-site-command-34.test" exists, created with "--type=html --alias-domains=bh-site-command-34-alias.test"
    And the site "bh-site-command-34-alias.test" does not exist
    When I run 'bin/ee site delete bh-site-command-34.test --yes'
    Then the command should succeed
    When I run 'bin/ee site create bh-site-command-34-alias.test'
    Then the command should succeed
    And the site "bh-site-command-34-alias.test" should exist
