Feature: PHP versions of php sites (create --php, update --php)

  @slow
  Scenario Outline: Create a php site on every supported PHP version
    When I run "bin/ee site create <site> --type=php --php=<version>"
    Then the command should succeed
    And the site "<site>" should have the field "php_version" set to "<version>"
    And the "php" service of site "<site>" should use the pinned "<image>" image
    And the php service of site "<site>" should run PHP "<runs>"
    And a GET request to "http://<site>/" should return 200
    And the response should contain "Congratulations!"
    And the file "/opt/easyengine/sites/<site>/config/php/php/conf.d/custom.ini" should contain "<sendmail>"

    # "latest" maps to the php8.4 image (Site_PHP_Docker), not to the newest supported version.
    Examples:
      | site                     | version | image              | runs | sendmail                    |
      | bh-site-type-php-40.test | 5.6     | easyengine/php5.6  | 5.6  | sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com |
      | bh-site-type-php-41.test | 7.0     | easyengine/php7.0  | 7.0  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-42.test | 7.2     | easyengine/php7.2  | 7.2  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-43.test | 7.3     | easyengine/php7.3  | 7.3  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-44.test | 7.4     | easyengine/php7.4  | 7.4  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-45.test | 8.0     | easyengine/php8.0  | 8.0  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-46.test | 8.1     | easyengine/php8.1  | 8.1  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-47.test | 8.2     | easyengine/php8.2  | 8.2  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-48.test | 8.3     | easyengine/php8.3  | 8.3  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-49.test | 8.4     | easyengine/php8.4  | 8.4  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-50.test | 8.5     | easyengine/php8.5  | 8.5  | sendmail_path = /usr/bin/msmtp -t |
      | bh-site-type-php-51.test | latest  | easyengine/php8.4  | 8.4  | sendmail_path = /usr/bin/msmtp -t |

  Scenario: Update the PHP version of a php site and keep custom php settings
    Given a php site "bh-site-type-php-52.test" exists
    And I append "; bh-custom-ini-marker" to the file "/opt/easyengine/sites/bh-site-type-php-52.test/config/php/php/conf.d/custom.ini"
    When I run "bin/ee site update bh-site-type-php-52.test --php=8.3"
    Then the command should succeed
    And STDOUT should contain "Starting php version update for: bh-site-type-php-52.test"
    And STDOUT should contain "Success: Updated site bh-site-type-php-52.test to PHP version: 8.3"
    And the site "bh-site-type-php-52.test" should have the field "php_version" set to "8.3"
    And the docker-compose file of site "bh-site-type-php-52.test" should use the pinned "easyengine/php8.3" image
    And the "php" service of site "bh-site-type-php-52.test" should use the pinned "easyengine/php8.3" image
    And the php service of site "bh-site-type-php-52.test" should run PHP "8.3"
    And the directory "/opt/easyengine/sites/bh-site-type-php-52.test/.backup/config/php-8.4" should exist
    And the file "/opt/easyengine/sites/bh-site-type-php-52.test/config/php/php/conf.d/custom.ini" should contain "; bh-custom-ini-marker"
    And I wait until a GET request to "http://bh-site-type-php-52.test/" returns 200 within 60 seconds

  Scenario: Updating to the current PHP version is refused
    Given a php site "bh-site-type-php-49.test" created with "--php=8.4" exists
    When I run "bin/ee site update bh-site-type-php-49.test --php=8.4"
    Then the return code should be 1
    And STDERR should contain "Error: Site bh-site-type-php-49.test is already at PHP version: 8.4"

  Scenario Outline: An unsupported PHP version is refused by site update
    Given a php site "bh-site-type-php-49.test" created with "--php=8.4" exists
    When I run "bin/ee site update bh-site-type-php-49.test --php=<version>"
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'php'"
    And the site "bh-site-type-php-49.test" should have the field "php_version" set to "8.4"

    Examples:
      | version |
      | 7.1     |
      | latest  |
      | 9.9     |

  Scenario: Updating from PHP 5.6 switches sendmail to msmtp
    Given a php site "bh-site-type-php-53.test" created with "--php=5.6" exists
    When I run "bin/ee site update bh-site-type-php-53.test --php=8.4"
    Then the command should succeed
    And the php service of site "bh-site-type-php-53.test" should run PHP "8.4"
    And the file "/opt/easyengine/sites/bh-site-type-php-53.test/config/php/php/conf.d/custom.ini" should contain "sendmail_path = /usr/bin/msmtp -t"
    And I wait until a GET request to "http://bh-site-type-php-53.test/" returns 200 within 60 seconds

  Scenario: Updating to PHP 5.6 switches msmtp to sendmail
    Given a php site "bh-site-type-php-54.test" exists
    When I run "bin/ee site update bh-site-type-php-54.test --php=5.6"
    Then the command should succeed
    And the php service of site "bh-site-type-php-54.test" should run PHP "5.6"
    And the file "/opt/easyengine/sites/bh-site-type-php-54.test/config/php/php/conf.d/custom.ini" should contain "sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com"
    And I wait until a GET request to "http://bh-site-type-php-54.test/" returns 200 within 60 seconds

  Scenario: The PHP version of a disabled site can not be updated
    Given a php site "bh-site-type-php-55.test" exists
    And the site "bh-site-type-php-55.test" is disabled
    When I run "bin/ee site update bh-site-type-php-55.test --php=8.3"
    Then the return code should be 1
    And STDERR should contain "Error: Site bh-site-type-php-55.test is not enabled. Use `ee site enable bh-site-type-php-55.test` to enable it."
    And the site "bh-site-type-php-55.test" should have the field "php_version" set to "8.4"

  Scenario: Updating a site that does not exist fails
    When I run "bin/ee site update bh-site-type-php-198.test --php=8.3"
    Then the return code should be 1
    And STDERR should contain "Could not find the site you wish to run site update command on."
