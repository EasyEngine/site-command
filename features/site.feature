Feature: Site Command

  Scenario: ee throws error when run without root
    Given 'bin/ee' is installed
    When I run 'bin/ee'
    Then STDERR should return exactly
    """
    Error: Please run `ee` with root privileges.
    """

  Scenario: ee executable is command working correctly
    Given 'bin/ee' is installed
    When I run 'sudo bin/ee'
    Then STDOUT should return something like
    """
    NAME

      ee
    """

  Scenario: Check site command is present
    When I run 'sudo bin/ee site'
    Then STDOUT should return something like
    """
    usage: ee site
    """

  Scenario: Create wp site successfully
    When I run 'sudo bin/ee site create wp.test --wp'
    Then The site 'wp.test' should have webroot
      And The site 'wp.test' should have WordPress
      And Request on 'wp.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wpsubdir site successfully
    When I run 'sudo bin/ee site create wpsubdir.test --wpsubdir'
      And I create subsite '1' in 'wpsubdir.test'
    Then The site 'wpsubdir.test' should have webroot
      And The site 'wpsubdir.test' should have WordPress
      And The site 'wpsubdir.test' should be 'subdir' multisite
      And Request on 'wpsubdir.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wpsubdom site successfully
    When I run 'sudo bin/ee site create wpsubdom.test --wpsubdom'
      And I create subsite '1' in 'wpsubdom.test'
    Then The site 'wpsubdom.test' should have webroot
      And The site 'wpsubdom.test' should have WordPress
      And The site 'wpsubdom.test' should be 'subdomain' multisite
      And Request on 'wpsubdom.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: List the sites
    When I run 'sudo bin/ee site list --format=text'
    Then STDOUT should return exactly
    """
    wp.test
    wpsubdom.test
    wpsubdir.test
    """

  Scenario: Delete the sites
    When I run 'sudo bin/ee site delete wp.test --yes'
    Then STDOUT should return something like
    """
    Site wp.test deleted.
    """
      And STDERR should return exactly
      """
      """
      And The 'wp.test' db entry should be removed
      And The 'wp.test' webroot should be removed
      And Following containers of site 'wp.test' should be removed:
        | container  |
        | nginx      |
        | php        |
        | db         |
        | redis      |
        | phpmyadmin |
