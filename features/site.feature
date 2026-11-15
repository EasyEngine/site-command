Feature: Site command basics
  Routing, help, argument validation and error messages that need no site of their own.

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
    Then STDOUT should contain "usage: ee site"

  Scenario: Help lists every site subcommand
    When I run 'bin/ee help site'
    Then the command should succeed
    And STDOUT should contain "backup"
    And STDOUT should contain "clean"
    And STDOUT should contain "clone"
    And STDOUT should contain "create"
    And STDOUT should contain "delete"
    And STDOUT should contain "disable"
    And STDOUT should contain "enable"
    And STDOUT should contain "info"
    And STDOUT should contain "list"
    And STDOUT should contain "refresh"
    And STDOUT should contain "reload"
    And STDOUT should contain "restart"
    And STDOUT should contain "restore"
    And STDOUT should contain "share"
    And STDOUT should contain "ssl-info"
    And STDOUT should contain "ssl-renew"
    And STDOUT should contain "ssl-verify"
    And STDOUT should contain "sync"
    And STDOUT should contain "update"

  Scenario: Help of site create documents the html options
    When I run 'bin/ee help site create'
    Then the command should succeed
    And STDOUT should contain "--ssl"
    And STDOUT should contain "--alias-domains"
    And STDOUT should contain "--public-dir"
    And STDOUT should contain "--skip-status-check"

  Scenario: Help for an unregistered site type fails
    When I run 'bin/ee help site --type=bh-bogus'
    Then the command should fail
    And STDERR should contain "'bh-bogus' is not a registered site type of 'ee site --type=bh-bogus'"

  Scenario: cmd-dump prints the site command tree as JSON
    When I run 'bin/ee site cmd-dump'
    Then the command should succeed
    And STDOUT should be valid JSON
    And STDOUT should contain "ssl-renew"

  Scenario: Creating a site of an unregistered type fails
    Given the site "bh-site-command-1.test" does not exist
    When I run 'bin/ee site create bh-site-command-1.test --type=bh-bogus'
    Then the command should fail
    And STDERR should contain "'bh-bogus' is not a registered site type of 'ee site --type=bh-bogus'"
    And the site "bh-site-command-1.test" should not exist
    And the directory "{SITES_DIR}/bh-site-command-1.test" should not exist

  Scenario: Creating a site without a name prints the usage
    When I run 'bin/ee site create'
    Then the exit code should be 1
    And STDOUT should contain "usage: ee site create <site-name>"

  Scenario Outline: Subcommands that need a site fail when none is given outside a site directory
    When I run 'bin/ee site <command>'
    Then the command should fail
    And STDERR should contain "Could not find the site you wish to run site <function> command on."

    Examples:
      | command  | function |
      | info     | info     |
      | update   | update   |
      | enable   | enable   |
      | disable  | disable  |
      | restart  | restart  |
      | reload   | reload   |
      | clean    | clean    |
      | ssl-info | ssl_info |
      | backup   | backup   |
      | restore  | restore  |

  Scenario: ssl-renew without a site fails outside a site directory
    Given the EasyEngine config value "le-mail" is set
    When I run 'bin/ee site ssl-renew'
    Then the command should fail
    And STDERR should contain "Could not find the site you wish to run site ssl_renew command on."

  Scenario Outline: Subcommands with a required site name print the usage without one
    When I run 'bin/ee site <command>'
    Then the exit code should be 1
    And STDOUT should contain "usage: ee site <command>"

    Examples:
      | command    |
      | delete     |
      | ssl-verify |
      | share      |
      | clone      |
      | sync       |

  Scenario Outline: Subcommands that look the site up directly report an unknown site
    When I run 'bin/ee site <command> bh-site-command-9.test'
    Then the command should fail
    And STDERR should contain "Site bh-site-command-9.test does not exist."

    Examples:
      | command    |
      | delete     |
      | refresh    |
      | ssl-verify |
      | ssl        |

  Scenario Outline: Subcommands that auto-detect the site report an unknown site
    When I run 'bin/ee site <command> bh-site-command-9.test'
    Then the command should fail
    And STDERR should contain "Could not find the site you wish to run site <function> command on."

    Examples:
      | command  | function |
      | info     | info     |
      | enable   | enable   |
      | disable  | disable  |
      | restart  | restart  |
      | update   | update   |
      | share    | share    |

  Scenario: Unknown parameters are rejected
    When I run 'bin/ee site list --bh-unknown'
    Then the command should fail
    And STDERR should contain "unknown --bh-unknown parameter"

  Scenario: Extra positional arguments are rejected
    Given the html site "bh-site-command-2.test" exists
    When I run 'bin/ee site info bh-site-command-2.test bh-site-command-2.test'
    Then the command should fail
    And STDERR should contain "Too many positional arguments"
