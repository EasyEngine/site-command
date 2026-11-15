Feature: Clone and sync sites
  `ee site clone` / `ee site sync` on the same host. The commands shell out to `ee` from PATH (not bin/ee), so PATH must point at the same install.
  Remote (user@host:site) transfers need a second SSH-reachable EE host and are not covered.

  Background:
    Given "ee" is installed

  Scenario: Source and destination cannot both be implicit
    When I run 'bin/ee site clone . .'
    Then the command should fail
    And STDERR should contain "No sitename found in source and destination site."

  Scenario: Source and destination cannot both be remote
    When I run 'bin/ee site clone root@bh-host-a:bh-site-command-150.test root@bh-host-b:bh-site-command-151.test'
    Then the command should fail
    And STDERR should contain "Both source and destination sites cannot be remote."

  Scenario: A site cannot be cloned onto itself
    When I run 'bin/ee site clone bh-site-command-150.test bh-site-command-150.test'
    Then the command should fail
    And STDERR should contain "Cannot copy 'bh-site-command-150.test' on 'localhost' to 'bh-site-command-150.test' on 'localhost'"

  Scenario: A malformed remote location is rejected
    When I run 'bin/ee site clone root@bh-site-command-150.test bh-site-command-151.test'
    Then the command should fail
    And STDERR should contain "Invalid format for remote site. Please use [user@ssh-hostname:] sitename"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): the same-site guard only knows the literal "localhost", so a loopback address syncs a site onto itself.
  Scenario: A site cannot be synced onto itself through a loopback address
    When I run 'bin/ee site sync bh-site-command-150.test root@127.0.0.1:bh-site-command-150.test'
    Then the command should fail
    And STDERR should contain "Cannot copy 'bh-site-command-150.test' on 'localhost' to 'bh-site-command-150.test' on '127.0.0.1'"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): the host part accepts spaces, so ssh options pass through.
  Scenario: ssh options in a remote location are rejected
    When I run "bin/ee site clone 'root@bh-host-a -oProxyCommand=true:bh-site-command-150.test' bh-site-command-151.test"
    Then the command should fail
    And STDERR should contain "Invalid format for remote site. Please use [user@ssh-hostname:] sitename"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): `user@host:.` gives the site name "." instead of "same name as the other side".
  Scenario: A remote dot means the name of the other side
    When I run 'bin/ee site clone root@bh-host-a:. .'
    Then the command should fail
    And STDERR should contain "No sitename found in source and destination site."

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): site names go into shell commands unchecked.
  Scenario: A site name with shell syntax is rejected
    When I run "bin/ee site clone 'bh-site-command-150.test;true' bh-site-command-151.test"
    Then the command should fail
    And STDERR should contain "Invalid site name: bh-site-command-150.test;true"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): the sync help shows `--ssl=le --wildcard`, which sync doesn't accept.
  Scenario: The sync help has no SSL example
    When I run 'bin/ee help site sync'
    Then the command should succeed
    And STDOUT should not contain "--wildcard"

  # With no site on the host the error is "Unable to get site list" instead, so keep one site around.
  Scenario: Cloning a site that does not exist fails
    Given the html site "bh-site-command-154.test" exists
    And the site "bh-site-command-159.test" does not exist
    And the site "bh-site-command-151.test" does not exist
    When I run 'bin/ee site clone bh-site-command-159.test bh-site-command-151.test'
    Then the command should fail
    And STDERR should contain "Unable to find 'bh-site-command-159.test' on 'localhost'"
    And the site "bh-site-command-151.test" should not exist

  Scenario: Cloning a disabled site fails
    Given the html site "bh-site-command-152.test" exists
    And the site "bh-site-command-152.test" is disabled
    When I run 'bin/ee site clone bh-site-command-152.test bh-site-command-153.test'
    Then the command should fail
    And STDERR should contain "The site that you want to clone has been disabled. Please enable the site and clone again."
    And the site "bh-site-command-153.test" should not exist

  Scenario: Only WordPress sites can be cloned
    Given the html site "bh-site-command-154.test" exists
    And the site "bh-site-command-155.test" does not exist
    When I run 'bin/ee site clone bh-site-command-154.test bh-site-command-155.test'
    Then the command should fail
    And STDERR should contain "Only clone of WordPress sites is supported as of now."
    And the site "bh-site-command-155.test" should not exist

  Scenario: Syncing to a site that does not exist fails
    Given the html site "bh-site-command-154.test" exists
    And the site "bh-site-command-159.test" does not exist
    When I run 'bin/ee site sync bh-site-command-154.test bh-site-command-159.test'
    Then the command should fail
    And STDERR should contain "Unable to find 'bh-site-command-159.test' on 'localhost'"

  Scenario: Only WordPress sites can be synced
    Given the html site "bh-site-command-154.test" exists
    And the html site "bh-site-command-156.test" exists
    When I run 'bin/ee site sync bh-site-command-154.test bh-site-command-156.test'
    Then the command should fail
    And STDERR should contain "Only Sync of WordPress sites is supported as of now."

  @wp
  Scenario: Clone a WordPress site on the same host
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-158.test" does not exist
    And I write "bh-site-command-clone-marker" to the file "{SITES_DIR}/bh-site-command-157.test/app/htdocs/bh-marker.txt"
    When I run "bin/ee shell bh-site-command-157.test --skip-tty --command='wp option update blogname bh-site-command-clone-source'"
    Then the command should succeed
    When I run 'bin/ee site clone bh-site-command-157.test bh-site-command-158.test'
    Then the command should succeed
    And STDOUT should contain "Site cloned successfully."
    And the site "bh-site-command-158.test" should exist
    And the "site_type" field of site "bh-site-command-158.test" in the database should be "wp"
    And the file "{SITES_DIR}/bh-site-command-158.test/app/htdocs/bh-marker.txt" should contain "bh-site-command-clone-marker"
    When I run "bin/ee shell bh-site-command-158.test --skip-tty --command='wp option get blogname'"
    Then STDOUT should contain "bh-site-command-clone-source"
    When I run "bin/ee shell bh-site-command-158.test --skip-tty --command='wp option get siteurl'"
    Then STDOUT should contain "bh-site-command-158.test"
    When I wait up to 60 seconds for "http://bh-site-command-158.test/" to return 200

  @wp
  Scenario: Cloning onto an existing site fails and keeps that site
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-158.test" exists, created with "--type=wp"
    When I run 'bin/ee site clone bh-site-command-157.test bh-site-command-158.test'
    Then the command should fail
    And STDERR should contain "already exists on 'localhost'"
    And the site "bh-site-command-158.test" should exist
    And the site "bh-site-command-158.test" should be enabled

  @wp
  Scenario: Sync only files
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-158.test" exists, created with "--type=wp"
    And I write "bh-site-command-files-v2" to the file "{SITES_DIR}/bh-site-command-157.test/app/htdocs/bh-marker.txt"
    When I run "bin/ee shell bh-site-command-157.test --skip-tty --command='wp option update blogname bh-site-command-files-only'"
    Then the command should succeed
    When I run 'bin/ee site sync bh-site-command-157.test bh-site-command-158.test --files'
    Then the command should succeed
    And STDOUT should contain "Site synced successfully"
    And the file "{SITES_DIR}/bh-site-command-158.test/app/htdocs/bh-marker.txt" should contain "bh-site-command-files-v2"
    When I run "bin/ee shell bh-site-command-158.test --skip-tty --command='wp option get blogname'"
    Then the command should succeed
    And STDOUT should not contain "bh-site-command-files-only"

  @wp
  Scenario: Sync only the database
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-158.test" exists, created with "--type=wp"
    And I write "bh-site-command-db-only-file" to the file "{SITES_DIR}/bh-site-command-157.test/app/htdocs/bh-db-only.txt"
    When I run "bin/ee shell bh-site-command-157.test --skip-tty --command='wp option update blogname bh-site-command-db-v2'"
    And I run 'bin/ee site sync bh-site-command-157.test bh-site-command-158.test --db'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-158.test/app/htdocs/bh-db-only.txt" should not exist
    When I run "bin/ee shell bh-site-command-158.test --skip-tty --command='wp option get blogname'"
    Then STDOUT should contain "bh-site-command-db-v2"
    When I run "bin/ee shell bh-site-command-158.test --skip-tty --command='wp option get home'"
    Then STDOUT should contain "bh-site-command-158.test"

  @wp
  Scenario: Sync only uploads
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-158.test" exists, created with "--type=wp"
    And I write "bh-site-command-upload" to the file "{SITES_DIR}/bh-site-command-157.test/app/htdocs/wp-content/uploads/bh-upload.txt"
    And I write "bh-site-command-not-an-upload" to the file "{SITES_DIR}/bh-site-command-157.test/app/htdocs/bh-not-upload.txt"
    When I run 'bin/ee site sync bh-site-command-157.test bh-site-command-158.test --uploads'
    Then the command should succeed
    And the file "{SITES_DIR}/bh-site-command-158.test/app/htdocs/wp-content/uploads/bh-upload.txt" should contain "bh-site-command-upload"
    And the file "{SITES_DIR}/bh-site-command-158.test/app/htdocs/bh-not-upload.txt" should not exist

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): `?? false && … ?? false` precedence lets a missing --ssl-crt through to is_file(null).
  @wp
  Scenario: Cloning with --ssl=custom needs both certificate files
    Given the site "bh-site-command-157.test" exists, created with "--type=wp"
    And the site "bh-site-command-170.test" does not exist
    When I run 'bin/ee site clone bh-site-command-157.test bh-site-command-170.test --ssl=custom --ssl-key=/etc/hostname'
    Then the command should fail
    And STDERR should contain "You need to specify --ssl-crt and --ssl-key with --ssl=custom"
    And STDERR should not contain "is_file"
    And the site "bh-site-command-170.test" should not exist

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): the admin password goes unquoted into `ee site create`, so a space, ; or & breaks the create.
  @wp
  Scenario: Clone copies an admin password with spaces and shell operators
    Given the site "bh-site-command-171.test" exists, created with "--type=wp --admin-pass='bh pw;1&x'"
    And the site "bh-site-command-172.test" does not exist
    When I run 'bin/ee site clone bh-site-command-171.test bh-site-command-172.test'
    Then the command should succeed
    And STDOUT should contain "Site cloned successfully."
    And the "app_admin_password" field of site "bh-site-command-172.test" in the database should be "bh pw;1&x"

  # Regression check (fixed in v4.13.2, EasyEngine/site-command#510): values with $ or quotes would be expanded by site-type-wp's double-quoted `bash -c`, so clone leaves them out.
  @wp
  Scenario: Clone does not pass an admin password the destination shell would expand
    Given the site "bh-site-command-174.test" exists, created with "--type=wp --admin-pass='bh$pw1xyz'"
    And the site "bh-site-command-173.test" does not exist
    When I run 'bin/ee site clone bh-site-command-174.test bh-site-command-173.test'
    Then the command should succeed
    And STDERR should contain "Not copying --admin-pass of bh-site-command-174.test"
    And the "app_admin_password" field of site "bh-site-command-173.test" in the database should not be "bh$pw1xyz"
