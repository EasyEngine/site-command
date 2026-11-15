@le
Feature: Let's Encrypt certificates
  Real issuance against public DNS (*.ee4-test.rt.gw points at the test host). Names include {RUN_ID} so repeated runs never hit
  the duplicate-certificate limit. Budget per full run: 4 issuances (create, alias add, alias delete, update --ssl=le); the wildcard
  and failing-alias scenarios create orders but issue nothing. Scenarios run in order and share LE1/LE2/LE3 to stay in budget;
  run alone, a scenario issues its own certificate.

  Background:
    Given the EasyEngine config value "le-mail" is set
    And the variable "LE1" is "bh-site-command-le1-{RUN_ID}.ee4-test.rt.gw"
    And the variable "LE2" is "bh-site-command-le2-{RUN_ID}.ee4-test.rt.gw"
    And the variable "LE3" is "bh-site-command-le3-{RUN_ID}.ee4-test.rt.gw"

  Scenario: Create a site with a Let's Encrypt certificate
    Given the site "{LE1}" does not exist
    When I run 'bin/ee site create {LE1} --ssl=le'
    Then the command should succeed
    And the "site_ssl" field of site "{LE1}" in the database should be "le"
    And the "site_ssl_wildcard" field of site "{LE1}" in the database should be "0"
    And the file "{PROXY_DIR}/certs/{LE1}.crt" should exist
    And the file "{PROXY_DIR}/certs/{LE1}.key" should exist
    And the directory "{PROXY_DIR}/acme-conf/certs/{LE1}" should exist
    And the hosts file should not contain an entry for "{LE1}"
    # The proxy reload that picks up the new certificate is asynchronous.
    When I wait up to 60 seconds for "https://{LE1}/" to return 200
    And I send a verified GET request to "https://{LE1}/"
    Then the response code should be 200
    And the response body should contain "Your html site with EasyEngine"
    And the TLS certificate served for "{LE1}" should cover "{LE1}"
    And the TLS certificate served for "{LE1}" should cover "www.{LE1}"
    And the TLS certificate served for "{LE1}" should be issued by "{LE_ISSUER}"
    When I send a GET request to "http://{LE1}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LE1}/"
    When I send a verified GET request to "https://www.{LE1}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LE1}/"
    When I run 'bin/ee site ssl-info {LE1}'
    Then the JSON field "0.ssl_type" in STDOUT should be "le"
    And the JSON field "0.issued_by_CN" in STDOUT should be "{LE_ISSUER}"
    And the JSON field "0.SANs" in STDOUT should contain "www.{LE1}"

  Scenario: ssl-renew leaves a certificate that is not due alone
    Given the site "{LE1}" exists, created with "--type=html --ssl=le"
    When I run 'bin/ee site ssl-info {LE1}'
    And I save the JSON field "0.serial_number" in STDOUT as "SERIAL"
    And I run 'bin/ee site ssl-renew {LE1}'
    Then the command should succeed
    And STDOUT should contain "SSL renewal completed."
    When I run 'bin/ee site ssl-info {LE1}'
    Then the JSON field "0.serial_number" in STDOUT should be "{SERIAL}"

  Scenario: Adding an alias reissues the certificate to cover it
    Given the site "{LE1}" exists, created with "--type=html --ssl=le"
    When I run 'bin/ee site update {LE1} --add-alias-domains=a1.{LE1}'
    Then the command should succeed
    And STDOUT should contain "Updating and force renewing SSL certificate to accomodated alias domain changes."
    And STDOUT should contain "Alias domains updated on site {LE1}."
    And the "alias_domains" field of site "{LE1}" in the database should be "{LE1},a1.{LE1}"
    And the "site_ssl" field of site "{LE1}" in the database should be "le"
    When I wait up to 60 seconds for "https://a1.{LE1}/" to return 200
    And I send a verified GET request to "https://a1.{LE1}/"
    Then the response code should be 200
    And the TLS certificate served for "a1.{LE1}" should cover "a1.{LE1}"
    And the TLS certificate served for "{LE1}" should cover "{LE1}"

  Scenario: Removing an alias reissues the certificate without it
    Given the site "{LE1}" exists, created with "--type=html --ssl=le --alias-domains=a1.{LE1}"
    When I run 'bin/ee site update {LE1} --delete-alias-domains=a1.{LE1}'
    Then the command should succeed
    And the "alias_domains" field of site "{LE1}" in the database should be "{LE1}"
    When I wait up to 60 seconds for "https://{LE1}/" to return 200
    And I send a verified GET request to "https://{LE1}/"
    Then the response code should be 200
    And the TLS certificate served for "{LE1}" should not cover "a1.{LE1}"

  Scenario: A wildcard certificate waits for the DNS challenge
    Given the EasyEngine config value "cloudflare-api-key" is not set
    And the site "{LE3}" does not exist
    When I run 'bin/ee site create {LE3} --ssl=le --wildcard'
    Then the command should succeed
    And STDOUT should contain "Run `ee site ssl-verify {LE3}` once the DNS changes have propagated"
    And the "site_ssl_wildcard" field of site "{LE3}" in the database should be "1"
    When I run 'bin/ee site ssl-info {LE3}'
    Then the JSON field "0.status" in STDOUT should be "Certificate file not found / yet to be issued."
    When I run 'bin/ee site ssl-info {LE3} --get-dns-records'
    Then the command should succeed
    And the JSON field "0.dns_challenges" in STDOUT should contain "_acme-challenge.{LE3}"

  # --all walks every LE site on the host (and force-enables expired ones), so it only runs when all of them are ours.
  @destructive
  Scenario: ssl-renew --all skips wildcard sites without Cloudflare credentials
    Given the EasyEngine config value "cloudflare-api-key" is not set
    And no enabled Let's Encrypt site other than this suite's exists
    And the site "{LE3}" exists, created with "--type=html --ssl=le --wildcard"
    When I run 'bin/ee site ssl-renew --all'
    Then the command should succeed
    And STDERR should contain "Wildcard site found: {LE3}, skipping it"

  Scenario: Enable Let's Encrypt on an existing site
    Given the html site "{LE2}" exists
    When I run 'bin/ee site update {LE2} --ssl=le'
    Then the command should succeed
    And STDOUT should contain "Enabled SSL for {LE2}"
    And the "site_ssl" field of site "{LE2}" in the database should be "le"
    When I wait up to 60 seconds for "https://{LE2}/" to return 200
    And I send a verified GET request to "https://{LE2}/"
    Then the response code should be 200
    And the TLS certificate served for "{LE2}" should be issued by "{LE_ISSUER}"

  # Regression check (fixed in v4.13.1, EasyEngine/site-command#505): a failed issuance used to only warn, save the alias with SSL "Not Enabled" and revoke the served cert.
  # The alias is under the reserved .invalid TLD: the order is refused outright, so nothing outside *.ee4-test.rt.gw is validated.
  Scenario: An alias that fails validation is rejected and the site keeps its certificate
    Given the site "{LE2}" exists, created with "--type=html --ssl=le"
    When I run 'bin/ee site update {LE2} --add-alias-domains=bh-site-command-nx-{RUN_ID}.invalid'
    Then the command should fail
    And STDERR should contain "Alias domains of {LE2} were not changed"
    And STDOUT should not contain "Alias domains updated"
    And the "alias_domains" field of site "{LE2}" in the database should be "{LE2}"
    And the "site_ssl" field of site "{LE2}" in the database should be "le"
    And the directory "{PROXY_DIR}/acme-conf/var/bh-site-command-nx-{RUN_ID}.invalid" should not exist
    When I wait up to 60 seconds for "https://{LE2}/" to return 200
    And I send a verified GET request to "https://{LE2}/"
    Then the response code should be 200
    And the TLS certificate served for "{LE2}" should cover "{LE2}"
    When I run 'bin/ee site ssl-renew {LE2}'
    Then the command should succeed
    And STDERR should not contain "Only Letsencrypt certificate renewal is supported."

  # Turning SSL off removes the certificate and ACME state (no issuance: runs on the site of the scenario above).
  Scenario: Turning Let's Encrypt off removes the certificate
    Given the site "{LE2}" exists, created with "--type=html --ssl=le"
    When I run 'bin/ee site update {LE2} --ssl=off'
    Then the command should succeed
    And the file "{PROXY_DIR}/certs/{LE2}.crt" should not exist
    And the file "{PROXY_DIR}/certs/{LE2}.key" should not exist
    And the directory "{PROXY_DIR}/acme-conf/certs/{LE2}" should not exist
    And the directory "{PROXY_DIR}/acme-conf/var/{LE2}" should not exist
    When I wait up to 60 seconds for "http://{LE2}/" to return 200

  Scenario: Deleting a Let's Encrypt site revokes and removes its certificate
    Given the site "{LE1}" exists, created with "--type=html --ssl=le"
    When I run 'bin/ee site delete {LE1} --yes'
    Then the command should succeed
    And STDOUT should contain "Revoking certificate."
    And the site "{LE1}" should not exist
    And the file "{PROXY_DIR}/certs/{LE1}.crt" should not exist
    And the file "{PROXY_DIR}/certs/{LE1}.key" should not exist
    And the directory "{PROXY_DIR}/acme-conf/certs/{LE1}" should not exist
    And the directory "{PROXY_DIR}/acme-conf/var/{LE1}" should not exist

  # Regression check (fixed in v4.13.1, EasyEngine/site-command#505): delete used to leave acme-conf/var/www.<site> and alias dirs behind. Run after the delete scenario above.
  Scenario: Deleting a Let's Encrypt site leaves no ACME state behind
    Given the site "{LE1}" does not exist
    Then the directory "{PROXY_DIR}/acme-conf/var/www.{LE1}" should not exist
    And the directory "{PROXY_DIR}/acme-conf/var/a1.{LE1}" should not exist
