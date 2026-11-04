Feature: Self-signed, custom and inherited SSL
  `ee site create --ssl=self|custom|inherit`, `ee site ssl-info` and the `ee site ssl-renew` guards. Let's Encrypt is in le.feature.

  Scenario: Create a site with a self-signed certificate
    Given the site "bh-site-command-100.test" does not exist
    When I run 'bin/ee site create bh-site-command-100.test --ssl=self'
    Then the command should succeed
    And the "site_ssl" field of site "bh-site-command-100.test" in the database should be "self"
    And the "site_ssl_wildcard" field of site "bh-site-command-100.test" in the database should be "1"
    And the file "{PROXY_DIR}/certs/bh-site-command-100.test.crt" should exist
    And the file "{PROXY_DIR}/certs/bh-site-command-100.test.key" should exist
    And the hosts file should contain an entry for "bh-site-command-100.test"
    And the service "nginx" of site "bh-site-command-100.test" should not have the environment variable "HTTPS_METHOD" containing "nohttps"
    When I wait up to 60 seconds for "https://bh-site-command-100.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"
    And the TLS certificate served for "bh-site-command-100.test" should cover "bh-site-command-100.test"
    And the TLS certificate served for "bh-site-command-100.test" should cover "anything.bh-site-command-100.test"
    And the TLS certificate served for "bh-site-command-100.test" should be issued by "EasyEngine"
    When I send a GET request to "http://bh-site-command-100.test/"
    Then the response code should be 301
    And the response header "Location" should be "https://bh-site-command-100.test/"

  Scenario: Info of a self-signed site shows SSL and wildcard
    Given the site "bh-site-command-100.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site info bh-site-command-100.test'
    Then the command should succeed
    And STDOUT should match "/\|\s*Site\s*\|\s*https:\/\/bh-site-command-100\.test\s*\|/"
    And STDOUT should match "/\|\s*SSL\s*\|\s*Enabled\s*\|/"
    And STDOUT should match "/\|\s*SSL Wildcard\s*\|\s*Yes\s*\|/"

  Scenario: ssl-info shows the certificate of a self-signed site
    Given the site "bh-site-command-100.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site ssl-info bh-site-command-100.test'
    Then the command should succeed
    And STDOUT should be valid JSON
    And the JSON field "0.ssl_type" in STDOUT should be "self"
    And the JSON field "0.cert_file" in STDOUT should be "{PROXY_DIR}/certs/bh-site-command-100.test.crt"
    And the JSON field "0.issued_to_CN" in STDOUT should be "bh-site-command-100.test"
    And the JSON field "0.issued_by_CN" in STDOUT should be "EasyEngine"
    And the JSON field "0.SANs" in STDOUT should contain "*.bh-site-command-100.test"
    And the JSON field "0.status" in STDOUT should be "SSL certificate details loaded."

  Scenario: ssl-info supports other formats
    Given the site "bh-site-command-100.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site ssl-info bh-site-command-100.test --format=yaml'
    Then the command should succeed
    And STDOUT should contain "ssl_type: self"

  Scenario: ssl-info rejects an unsupported format
    Given the site "bh-site-command-100.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site ssl-info bh-site-command-100.test --format=xml'
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'format'"

  Scenario: ssl-info of a site without SSL
    Given the html site "bh-site-command-101.test" exists
    When I run 'bin/ee site ssl-info bh-site-command-101.test'
    Then the command should succeed
    And the JSON field "0.ssl_type" in STDOUT should be "off"
    And the JSON field "0.status" in STDOUT should be "SSL is not enabled for this site."

  Scenario: ssl-info DNS records of a site that does not use DNS-01
    Given the html site "bh-site-command-101.test" exists
    When I run 'bin/ee site ssl-info bh-site-command-101.test --get-dns-records'
    Then the command should succeed
    And the JSON field "0.warnings" in STDOUT should contain "This site does not use DNS-based (DNS-01) SSL challenge."

  Scenario Outline: ssl-renew only renews Let's Encrypt certificates
    Given the EasyEngine config value "le-mail" is set
    And the site "<site>" exists, created with "<flags>"
    When I run 'bin/ee site ssl-renew <site>'
    Then the command should fail
    And STDERR should contain "Only Letsencrypt certificate renewal is supported."

    Examples:
      | site                     | flags                   |
      | bh-site-command-101.test | --type=html             |
      | bh-site-command-100.test | --type=html --ssl=self  |

  Scenario: Create a site with a custom certificate
    Given the site "bh-site-command-103.test" does not exist
    When I run "openssl req -x509 -newkey rsa:2048 -nodes -days 30 -keyout {TMP}/bh.key -out {TMP}/bh.crt -subj '/CN=bh-site-command-103.test/O=bh-site-command-custom' -addext 'subjectAltName=DNS:bh-site-command-103.test'"
    Then the command should succeed
    When I run 'bin/ee site create bh-site-command-103.test --ssl=custom --ssl-key={TMP}/bh.key --ssl-crt={TMP}/bh.crt'
    Then the command should succeed
    And the "site_ssl" field of site "bh-site-command-103.test" in the database should be "custom"
    And the file "{PROXY_DIR}/certs/bh-site-command-103.test.crt" should be identical to the file "{TMP}/bh.crt"
    And the file "{PROXY_DIR}/certs/bh-site-command-103.test.key" should be identical to the file "{TMP}/bh.key"
    When I wait up to 60 seconds for "https://bh-site-command-103.test/" to return 200
    Then the TLS certificate served for "bh-site-command-103.test" should be issued by "bh-site-command-custom"
    When I run 'bin/ee site ssl-info bh-site-command-103.test'
    Then the JSON field "0.ssl_type" in STDOUT should be "custom"
    And the JSON field "0.issued_by_CN" in STDOUT should be "bh-site-command-103.test"

  Scenario: Custom SSL needs both a key and a certificate
    Given the site "bh-site-command-104.test" does not exist
    When I run 'bin/ee site create bh-site-command-104.test --ssl=custom'
    Then the command should fail
    And STDERR should contain "Pass --ssl-key and --ssl-crt for custom SSL"
    And the site "bh-site-command-104.test" should not exist
    And the directory "{SITES_DIR}/bh-site-command-104.test" should not exist

  Scenario: Custom SSL files must exist
    Given the site "bh-site-command-104.test" does not exist
    When I run 'bin/ee site create bh-site-command-104.test --ssl=custom --ssl-key={TMP}/missing.key --ssl-crt={TMP}/missing.crt'
    Then the command should fail
    And STDERR should contain "ssl-key OR ssl-crt path does not exist"
    And the site "bh-site-command-104.test" should not exist

  Scenario: A subdomain site inherits the wildcard certificate of its parent
    Given the site "bh-site-command-105.test" exists, created with "--type=html --ssl=self"
    And the site "sub.bh-site-command-105.test" does not exist
    When I run 'bin/ee site create sub.bh-site-command-105.test --ssl=inherit'
    Then the command should succeed
    And STDOUT should contain "Inherited certs from parent"
    And the "site_ssl" field of site "sub.bh-site-command-105.test" in the database should be "inherit"
    When I wait up to 60 seconds for "https://sub.bh-site-command-105.test/" to return 200
    Then the TLS certificate served for "sub.bh-site-command-105.test" should cover "sub.bh-site-command-105.test"
    When I run 'bin/ee site ssl-info sub.bh-site-command-105.test'
    Then the JSON field "0.cert_file" in STDOUT should be "{PROXY_DIR}/certs/bh-site-command-105.test.crt"

  Scenario: ssl-renew refuses inherited certificates
    Given the EasyEngine config value "le-mail" is set
    And the site "bh-site-command-105.test" exists, created with "--type=html --ssl=self"
    And the site "sub.bh-site-command-105.test" exists, created with "--ssl=inherit"
    When I run 'bin/ee site ssl-renew sub.bh-site-command-105.test'
    Then the command should fail
    And STDERR should contain "No need to renew certs for site who have inherited ssl. Please renew certificate of the parent site."

  Scenario: Inheriting needs an existing parent site
    Given the site "bh-site-command-106.test" does not exist
    And the site "sub.bh-site-command-106.test" does not exist
    When I run 'bin/ee site create sub.bh-site-command-106.test --ssl=inherit'
    Then the command should fail
    And STDERR should contain "Unable to find existing site: bh-site-command-106.test"
    And the site "sub.bh-site-command-106.test" should not exist
    And the directory "{SITES_DIR}/sub.bh-site-command-106.test" should not exist

  Scenario: Inheriting needs a parent with SSL
    Given the html site "bh-site-command-107.test" exists
    And the site "sub.bh-site-command-107.test" does not exist
    When I run 'bin/ee site create sub.bh-site-command-107.test --ssl=inherit'
    Then the command should fail
    And STDERR should contain "Cannot inherit from bh-site-command-107.test as site does not have SSL cert"
    And the site "sub.bh-site-command-107.test" should not exist

  Scenario: Inheriting cannot be combined with --wildcard
    Given the site "bh-site-command-105.test" exists, created with "--type=html --ssl=self"
    And the site "sub2.bh-site-command-105.test" does not exist
    When I run 'bin/ee site create sub2.bh-site-command-105.test --ssl=inherit --wildcard'
    Then the command should fail
    And STDERR should contain "Cannot use --wildcard with --ssl=inherit"
    And the site "sub2.bh-site-command-105.test" should not exist

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#513): the EE root CA lacks basicConstraints CA:TRUE, so OpenSSL 3.5 rejects every leaf it signed (error 79).
  Scenario: Self-signed site certificates verify against the EasyEngine root CA
    Given the site "bh-site-command-100.test" exists, created with "--type=html --ssl=self"
    When I run 'openssl verify -CAfile {PROXY_DIR}/self-signed-certs/rootCA.pem {PROXY_DIR}/certs/bh-site-command-100.test.crt'
    Then the command should succeed
