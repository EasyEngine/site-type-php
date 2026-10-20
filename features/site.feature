Feature: Site command presence and usage errors

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
    usage: ee site create <site-name> [--ssl] [--ssl-key=<ssl-key-path>] [--ssl-crt=<ssl-crt-path>] [--wildcard] [--type=<type>] [--alias-domains=<domains>] [--skip-status-check] [--public-dir]
    """

  Scenario: php site create without a site name prints the php usage
    When I run "bin/ee site create --type=php"
    Then the return code should be 1
    # The synopsis has a stray double space before [--with-db] (docblock indentation), so match the two halves.
    And STDOUT should contain "usage: ee site create <site-name> [--cache] [--admin-email=<admin-email>]"
    And STDOUT should contain "[--with-db] [--local-db] [--php=<php-version>] [--alias-domains=<domains>]"

  Scenario: Help for php site create lists every php option
    When I run "bin/ee help site create --type=php"
    Then the command should succeed
    And STDOUT should contain "Runs the standard PHP Site installation."
    And STDOUT should contain "[--cache]"
    And STDOUT should contain "[--admin-email=<admin-email>]"
    And STDOUT should contain "[--with-db]"
    And STDOUT should contain "[--local-db]"
    And STDOUT should contain "[--php=<php-version>]"
    And STDOUT should contain "[--alias-domains=<domains>]"
    And STDOUT should contain "[--dbname=<dbname>]"
    And STDOUT should contain "[--dbuser=<dbuser>]"
    And STDOUT should contain "[--dbpass=<dbpass>]"
    And STDOUT should contain "[--dbhost=<dbhost>]"
    And STDOUT should contain "[--with-local-redis]"
    And STDOUT should contain "[--skip-check]"
    And STDOUT should contain "[--skip-status-check]"
    And STDOUT should contain "[--ssl]"
    And STDOUT should contain "[--ssl-key=<ssl-key-path>]"
    And STDOUT should contain "[--ssl-crt=<ssl-crt-path>]"
    And STDOUT should contain "[--wildcard]"
    And STDOUT should contain "[--force]"
    And STDOUT should contain "[--public-dir]"
    And STDOUT should contain "default: 8.4"

  Scenario: Help for php site info, restart and reload shows the php variants
    When I run "bin/ee help site restart --type=php"
    Then the command should succeed
    And STDOUT should contain "[--php]"
    And STDOUT should contain "[--db]"
    When I run "bin/ee help site reload --type=php"
    Then the command should succeed
    And STDOUT should contain "[--php]"
    And STDOUT should not contain "[--db]"
    When I run "bin/ee help site info --type=php"
    Then the command should succeed
    And STDOUT should contain "[--format=<format>]"

  Scenario: Check site disable sub command is present
    When I run 'bin/ee site disable'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site disable command on.
    Either pass it as an argument: `ee site disable <site-name>`
    or run `ee site disable` from inside the site folder.
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

  Scenario: Check site info sub command is present
    When I run 'bin/ee site info'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run site info command on.
    Either pass it as an argument: `ee site info <site-name>`
    or run `ee site info` from inside the site folder.
    """

  Scenario: Check site delete sub command is present
    When I run 'bin/ee site delete'
    Then STDOUT should return exactly
    """
    usage: ee site delete <site-name> [--yes]
    """

  Scenario: An unknown site type is rejected
    When I run "bin/ee site create bh-site-type-php-199.test --type=bh-nope"
    Then the command should fail
    And STDERR should contain "'bh-nope' is not a registered site type"
    And the site "bh-site-type-php-199.test" should not exist
