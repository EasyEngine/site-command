Feature: Update alias domains
  `ee site update --add-alias-domains/--delete-alias-domains` validation, DB, proxy env and HTTP.

  Scenario: Add alias domains
    Given the html site "bh-site-command-60.test" exists
    When I run 'bin/ee site update bh-site-command-60.test --add-alias-domains=bh-site-command-60-a.test,b.bh-site-command-60.test'
    Then the command should succeed
    And STDOUT should contain "Alias domains updated on site bh-site-command-60.test."
    And the "alias_domains" field of site "bh-site-command-60.test" in the database should be "bh-site-command-60.test,bh-site-command-60-a.test,b.bh-site-command-60.test"
    And the service "nginx" of site "bh-site-command-60.test" should have the environment variable "VIRTUAL_HOST" containing "bh-site-command-60-a.test,b.bh-site-command-60.test"
    When I wait up to 60 seconds for "http://bh-site-command-60-a.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"
    When I wait up to 60 seconds for "http://b.bh-site-command-60.test/" to return 200
    When I run 'bin/ee site info bh-site-command-60.test'
    Then STDOUT should match "/\|\s*Alias Domains\s*\|\s*bh-site-command-60-a\.test,b\.bh-site-command-60\.test\s*\|/"

  Scenario: Adding an alias that is already present fails
    Given the site "bh-site-command-61.test" exists, created with "--type=html --alias-domains=bh-site-command-61-a.test"
    When I run 'bin/ee site update bh-site-command-61.test --add-alias-domains=bh-site-command-61-a.test'
    Then the command should fail
    And STDERR should contain "Alias domains: bh-site-command-61-a.test is/are already present on the site."

  Scenario: Adding present and new aliases skips the present ones with a warning
    Given the site "bh-site-command-61.test" exists, created with "--type=html --alias-domains=bh-site-command-61-a.test"
    When I run 'bin/ee site update bh-site-command-61.test --add-alias-domains=bh-site-command-61-a.test,bh-site-command-61-b.test'
    Then the command should succeed
    And STDERR should contain "Following domains: bh-site-command-61-a.test is/are already present on site, skipping addition of those."
    And the "alias_domains" field of site "bh-site-command-61.test" in the database should be "bh-site-command-61.test,bh-site-command-61-a.test,bh-site-command-61-b.test"

  Scenario: Delete an alias domain
    Given the site "bh-site-command-62.test" exists, created with "--type=html --alias-domains=bh-site-command-62-a.test,bh-site-command-62-b.test"
    When I run 'bin/ee site update bh-site-command-62.test --delete-alias-domains=bh-site-command-62-b.test'
    Then the command should succeed
    And STDOUT should contain "Alias domains updated on site bh-site-command-62.test."
    And the "alias_domains" field of site "bh-site-command-62.test" in the database should be "bh-site-command-62.test,bh-site-command-62-a.test"
    And the service "nginx" of site "bh-site-command-62.test" should not have the environment variable "VIRTUAL_HOST" containing "bh-site-command-62-b.test"
    # The site's nginx container is recreated, so the proxy briefly drops every domain of the site until docker-gen catches up.
    When I wait up to 60 seconds for "http://bh-site-command-62-a.test/" to return 200
    And I wait up to 60 seconds for "http://bh-site-command-62-b.test/" to not return 200

  Scenario: The primary domain cannot be deleted
    Given the site "bh-site-command-62.test" exists, created with "--type=html --alias-domains=bh-site-command-62-a.test,bh-site-command-62-b.test"
    When I run 'bin/ee site update bh-site-command-62.test --delete-alias-domains=bh-site-command-62.test'
    Then the command should fail
    And STDERR should contain "Primary site domain: `bh-site-command-62.test` can not be deleted."

  Scenario: Deleting an alias the site does not have fails
    Given the site "bh-site-command-62.test" exists, created with "--type=html --alias-domains=bh-site-command-62-a.test,bh-site-command-62-b.test"
    When I run 'bin/ee site update bh-site-command-62.test --delete-alias-domains=bh-site-command-62-zzz.test'
    Then the command should fail
    And STDERR should contain "Domains to delete is/are not a subset of already existing alias domains."

  Scenario: Another site cannot be added as an alias
    Given the html site "bh-site-command-63.test" exists
    And the html site "bh-site-command-64.test" exists
    When I run 'bin/ee site update bh-site-command-63.test --add-alias-domains=bh-site-command-64.test'
    Then the command should fail
    And STDERR should contain "bh-site-command-64.test already exists as a site."
    And the "alias_domains" field of site "bh-site-command-63.test" in the database should be "bh-site-command-63.test"

  Scenario: An alias of another site cannot be added
    Given the html site "bh-site-command-63.test" exists
    And the site "bh-site-command-65.test" exists, created with "--type=html --alias-domains=bh-site-command-65-a.test"
    When I run 'bin/ee site update bh-site-command-63.test --add-alias-domains=bh-site-command-65-a.test'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-65-a.test already exists as an alias domain for site: bh-site-command-65.test."

  Scenario: Add and delete aliases in one call
    Given the site "bh-site-command-66.test" exists, created with "--type=html --alias-domains=bh-site-command-66-a.test"
    When I run 'bin/ee site update bh-site-command-66.test --add-alias-domains=bh-site-command-66-b.test --delete-alias-domains=bh-site-command-66-a.test'
    Then the command should succeed
    And the "alias_domains" field of site "bh-site-command-66.test" in the database should be "bh-site-command-66.test,bh-site-command-66-b.test"

  Scenario: A wildcard alias serves every subdomain
    Given the html site "bh-site-command-67.test" exists
    When I run "bin/ee site update bh-site-command-67.test --add-alias-domains='*.bh-site-command-67.test'"
    Then the command should succeed
    And the "alias_domains" field of site "bh-site-command-67.test" in the database should be "bh-site-command-67.test,*.bh-site-command-67.test"
    When I wait up to 60 seconds for "http://anything.bh-site-command-67.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"

  Scenario: Changing aliases on a self-signed site keeps HTTPS
    Given the site "bh-site-command-69.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site update bh-site-command-69.test --add-alias-domains=bh-site-command-69-a.test'
    Then the command should succeed
    And STDOUT should contain "No SSL certificate action needed for self SSL on alias domain change."
    And the service "nginx" of site "bh-site-command-69.test" should not have the environment variable "HTTPS_METHOD" containing "nohttps"
    When I wait up to 60 seconds for "https://bh-site-command-69.test/" to return 200
    Then the TLS certificate served for "bh-site-command-69.test" should cover "bh-site-command-69.test" within 30 seconds

  # Regression check (fixed by EasyEngine/site-command#502): alias labels starting with "_" could clobber a site's _wildcard proxy files.
  Scenario: Alias domains with a label starting with an underscore are rejected
    Given the html site "bh-site-command-68.test" exists
    When I run 'bin/ee site update bh-site-command-68.test --add-alias-domains=_wildcard.bh-site-command-68.test'
    Then the command should fail
    And the "alias_domains" field of site "bh-site-command-68.test" in the database should be "bh-site-command-68.test"
