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
