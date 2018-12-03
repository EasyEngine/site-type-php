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

  Scenario: Check site create sub command is present
    When I run 'bin/ee site create'
    Then STDOUT should return exactly
    """
    usage: ee site create <site-name> [--ssl=<value>] [--wildcard] [--type=<type>] [--skip-status-check]
    """

  Scenario: Create php site successfully
    When I run 'bin/ee site create php.test --type=php'
    Then After delay of 5 seconds
      And The site 'php.test' should have webroot
      And The site 'php.test' should have index file
      And Request on 'php.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: List the sites
    When I run 'bin/ee site list --format=text'
    Then STDOUT should return exactly
    """
    php.test
    """

  Scenario: Check site disable sub command is present
    When I run 'bin/ee site disable'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site disable command on.
    Either pass it as an argument: `ee site disable <site-name>`
    or run `ee site disable` from inside the site folder.
    """

  Scenario: Disable the site
    When I run 'bin/ee site disable php.test'
    Then STDOUT should return exactly
    """
    Disabling site php.test.
    Success: Site php.test disabled.
    """

  Scenario: Check site enable sub command is present
    When I run 'bin/ee site enable'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site enable command on.
    Either pass it as an argument: `ee site enable <site-name>`
    or run `ee site enable` from inside the site folder.
    """

  Scenario: Check site reload sub command is present
    When I run 'bin/ee site reload'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site reload command on.
    Either pass it as an argument: `ee site reload <site-name>`
    or run `ee site reload` from inside the site folder.
    """

  Scenario: Reload site services
    When I run 'bin/ee site reload php.test'
    Then STDERR should return something like
    """
    Error: Site php.test is not enabled. Use `ee site enable php.test` to enable it.
    """

  Scenario: Enable the site
    When I run 'bin/ee site enable php.test'
    Then STDOUT should return exactly
    """
    Enabling site php.test.
    Success: Site php.test enabled.
    Running post enable configurations.
    Starting site's services.
    Success: Post enable configurations complete.
    """

  Scenario: Check site info sub command is present
    When I run 'bin/ee site info'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site info command on.
    Either pass it as an argument: `ee site info <site-name>`
    or run `ee site info` from inside the site folder.
    """

  Scenario: Details of the site uing site info command
    When I run 'bin/ee site info php.test'
    Then STDOUT should return something like
    """
    | Site      | http://php.test
    """

  Scenario: Reload site services
    When I run 'bin/ee site reload php.test'
    Then STDOUT should return something like
    """
    Reloading nginx
    """

  Scenario: Reload site nginx services
    When I run 'bin/ee site reload php.test --nginx'
    Then STDOUT should return something like
    """
    Reloading nginx
    """

  Scenario: Check site delete sub command is present
    When I run 'bin/ee site delete'
    Then STDOUT should return exactly
    """
    usage: ee site delete <site-name> [--yes]
    """

  Scenario: Delete the sites
    When I run 'bin/ee site delete php.test --yes'
    Then STDOUT should return something like
    """
    Site php.test deleted.
    """
      And STDERR should return exactly
      """
      """
      And The 'php.test' db entry should be removed
      And The 'php.test' webroot should be removed
      And Following containers of site 'php.test' should be removed:
        | container  |
        | nginx      |
        | php        |
        | db         |
        | redis      |
        | phpmyadmin |
