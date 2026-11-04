Feature: Create html sites
  `ee site create` for the html site type: files, containers, volumes, network, hosts entry, DB row and HTTP.

  Scenario: Create an html site
    Given the site "bh-site-command-10.test" does not exist
    When I run 'bin/ee site create bh-site-command-10.test --type=html'
    Then the command should succeed
    And STDOUT should contain "Configuration files copied."
    And STDOUT should contain "Site entry created."
    And STDOUT should contain "http://bh-site-command-10.test"
    And the site "bh-site-command-10.test" should exist
    And the site "bh-site-command-10.test" should be enabled
    And the "site_type" field of site "bh-site-command-10.test" in the database should be "html"
    And the "site_ssl" field of site "bh-site-command-10.test" in the database should be ""
    And the "alias_domains" field of site "bh-site-command-10.test" in the database should be "bh-site-command-10.test"
    And the "site_fs_path" field of site "bh-site-command-10.test" in the database should be "{SITES_DIR}/bh-site-command-10.test"
    And the "site_container_fs_path" field of site "bh-site-command-10.test" in the database should be "/var/www/htdocs"
    And the "proxy_cache" field of site "bh-site-command-10.test" in the database should be "off"
    And the file "{SITES_DIR}/bh-site-command-10.test/docker-compose.yml" should contain "io.easyengine.site=${VIRTUAL_HOST}"
    And the file "{SITES_DIR}/bh-site-command-10.test/.env" should contain "VIRTUAL_HOST=bh-site-command-10.test"
    And the file "{SITES_DIR}/bh-site-command-10.test/config/nginx/conf.d/main.conf" should contain "server_name bh-site-command-10.test;"
    And the file "{SITES_DIR}/bh-site-command-10.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs;"
    And the file "{SITES_DIR}/bh-site-command-10.test/config/nginx/custom/user.conf" should exist
    And the file "{SITES_DIR}/bh-site-command-10.test/app/htdocs/index.html" should contain "Your html site with EasyEngine"
    And the service "nginx" of site "bh-site-command-10.test" should be running
    And the service "nginx" of site "bh-site-command-10.test" should have the environment variable "HTTPS_METHOD" containing "nohttps"
    And the site "bh-site-command-10.test" should have 3 docker volumes
    And the docker network "bh-site-command-10.test" should exist
    And the hosts file should contain an entry for "bh-site-command-10.test"
    When I send a GET request to "http://bh-site-command-10.test/"
    Then the response code should be 200
    And the response body should contain "Your html site with EasyEngine"

  # EasyEngine/site-command#503: volume names drop "." and "-" and the compose project drops ".", so both names map to the volumes
  # bhsitecommand160xtest_* and the compose project bh-site-command-160xtest. The second create is refused.
  Scenario: A site whose docker volumes and compose project collide with an existing site is refused
    Given the html site "bh-site-command-160.x.test" exists
    When I run 'bin/ee site create bh-site-command-160x.test --type=html'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-160x.test would share docker volumes (bhsitecommand160xtest_*) with the existing site bh-site-command-160.x.test."
    And the site "bh-site-command-160x.test" should not exist
    And the site "bh-site-command-160.x.test" should have 3 docker volumes
    And the service "nginx" of site "bh-site-command-160.x.test" should be running
    When I send a GET request to "http://bh-site-command-160.x.test/"
    Then the response code should be 200

  Scenario: The old site assertions still work on a created site
    Given the html site "bh-site-command-10.test" exists
    Then The site 'bh-site-command-10.test' should have webroot
    And Request on 'bh-site-command-10.test' should contain following headers:
      | header           |
      | HTTP/1.1 200 OK  |

  Scenario: Creating a site that already exists fails and keeps the site
    Given the html site "bh-site-command-10.test" exists
    When I run 'bin/ee site create bh-site-command-10.test --type=html'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-10.test already exists."
    And the site "bh-site-command-10.test" should be enabled
    When I send a GET request to "http://bh-site-command-10.test/"
    Then the response code should be 200

  Scenario: A site name without a dot gets the .test TLD
    Given the site "bh-site-command-11.test" does not exist
    When I run 'bin/ee site create bh-site-command-11'
    Then the command should succeed
    And the site "bh-site-command-11.test" should exist
    And the site "bh-site-command-11" should not exist

  Scenario: The URL scheme and a trailing slash are stripped from the site name
    Given the site "bh-site-command-12.test" does not exist
    When I run 'bin/ee site create http://bh-site-command-12.test/'
    Then the command should succeed
    And the site "bh-site-command-12.test" should exist
    And the directory "{SITES_DIR}/bh-site-command-12.test" should exist

  Scenario: Upper-case site names are lower-cased
    Given the site "bh-site-command-13.test" does not exist
    When I run 'bin/ee site create BH-SITE-COMMAND-13.TEST'
    Then the command should succeed
    And the site "bh-site-command-13.test" should exist
    And the directory "{SITES_DIR}/bh-site-command-13.test" should exist

  Scenario: Create a site with a custom public directory
    Given the site "bh-site-command-14.test" does not exist
    When I run 'bin/ee site create bh-site-command-14.test --public-dir=public'
    Then the command should succeed
    And the "site_container_fs_path" field of site "bh-site-command-14.test" in the database should be "/var/www/htdocs/public"
    And the file "{SITES_DIR}/bh-site-command-14.test/app/htdocs/public/index.html" should exist
    And the file "{SITES_DIR}/bh-site-command-14.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs/public;"
    When I send a GET request to "http://bh-site-command-14.test/"
    Then the response code should be 200
    And the response body should contain "Your html site with EasyEngine"

  Scenario: Create a site without the status check
    Given the site "bh-site-command-15.test" does not exist
    When I run 'bin/ee site create bh-site-command-15.test --skip-status-check'
    Then the command should succeed
    And STDOUT should not contain "Checking and verifying site-up status"
    And the site "bh-site-command-15.test" should exist
    When I wait up to 60 seconds for "http://bh-site-command-15.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"

  Scenario: Create a site with alias domains
    Given the site "bh-site-command-16.test" does not exist
    When I run 'bin/ee site create bh-site-command-16.test --alias-domains=a.bh-site-command-16.test,bh-site-command-16-alias.test'
    Then the command should succeed
    And STDOUT should contain "a.bh-site-command-16.test,bh-site-command-16-alias.test"
    And the "alias_domains" field of site "bh-site-command-16.test" in the database should be "bh-site-command-16.test,a.bh-site-command-16.test,bh-site-command-16-alias.test"
    And the file "{SITES_DIR}/bh-site-command-16.test/config/nginx/conf.d/main.conf" should contain "server_name bh-site-command-16.test a.bh-site-command-16.test bh-site-command-16-alias.test;"
    And the service "nginx" of site "bh-site-command-16.test" should have the environment variable "VIRTUAL_HOST" containing "bh-site-command-16.test,a.bh-site-command-16.test,bh-site-command-16-alias.test"
    When I send a GET request to "http://bh-site-command-16-alias.test/"
    Then the response code should be 200
    And the response body should contain "Your html site with EasyEngine"
    When I send a GET request to "http://a.bh-site-command-16.test/"
    Then the response code should be 200

  Scenario: A domain that is an alias of another site cannot be created as a site
    Given the site "bh-site-command-16.test" exists, created with "--type=html --alias-domains=a.bh-site-command-16.test,bh-site-command-16-alias.test"
    When I run 'bin/ee site create bh-site-command-16-alias.test'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-16-alias.test already exists as an alias domain for site: bh-site-command-16.test."
    And the site "bh-site-command-16-alias.test" should not exist

  Scenario: An alias domain that belongs to another site is rejected on create
    Given the site "bh-site-command-16.test" exists, created with "--type=html --alias-domains=a.bh-site-command-16.test,bh-site-command-16-alias.test"
    And the site "bh-site-command-17.test" does not exist
    When I run 'bin/ee site create bh-site-command-17.test --alias-domains=bh-site-command-16-alias.test'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-16-alias.test already exists as an alias domain for site: bh-site-command-16.test."
    And the site "bh-site-command-17.test" should not exist
    And the directory "{SITES_DIR}/bh-site-command-17.test" should not exist

  Scenario: The EE v3 --html flag still selects the html type
    Given the site "bh-site-command-18.test" does not exist
    When I run 'bin/ee site create bh-site-command-18.test --html'
    Then the command should succeed
    And the "site_type" field of site "bh-site-command-18.test" in the database should be "html"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#509): site names are not validated, and unescaped shell sinks run before the create fails: the ";" splits
  # `docker volume create ... bhsitecommand22;idtest_htdocs`, creating a volume "bhsitecommand22" and running "idtest_htdocs".
  Scenario: Site names that are not valid hostnames are rejected before anything runs
    Given the site "bh-site-command-22;id.test" does not exist
    When I run "bin/ee site create 'bh-site-command-22;id.test'"
    Then the command should fail
    And STDERR should contain "Invalid site name"
    And the site "bh-site-command-22;id.test" should not exist
    And the directory "{SITES_DIR}/bh-site-command-22;id.test" should not exist
    And the site "bh-site-command-22;id.test" should have 0 docker volumes
    And the docker volume "bhsitecommand22" should not exist
