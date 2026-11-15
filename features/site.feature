Feature: Site Command

  Scenario: ee executable is command working correctly
    Given 'bin/ee' is installed
    When I run 'bin/ee'
    Then STDOUT should return something like
    """
    NAME

      ee
    """

  Scenario: Check site command is present
    When I run 'bin/ee site'
    Then STDOUT should return something like
    """
    usage: ee site
    """

  Scenario: Create html site successfully
    When I run 'bin/ee site create site.test --type=html'
    Then The site 'site.test' should have webroot
      And Request on 'site.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: List the sites
    When I run 'bin/ee site list --format=text'
    Then STDOUT should return exactly
    """
    site.test
    """

  Scenario: Add alias domain
    When I run 'bin/ee site update site.test --add-alias-domains=alias.site.test '
    And I run '/bin/bash -c 'echo "127.0.0.1 alias.site.test" >> /etc/hosts''
    Then STDOUT should return exactly
    """
    Success: Alias domains updated on site site.test.
    """
      And Request on 'alias.site.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Delete the sites
    When I run 'bin/ee site delete site.test --yes'
    Then STDOUT should return something like
    """
    Site site.test deleted.
    """
      And STDERR should return exactly
      """
      """
      And The 'site.test' db entry should be removed
      And The 'site.test' webroot should be removed
      And Following containers of site 'site.test' should be removed:
        | container  |
        | nginx      |

