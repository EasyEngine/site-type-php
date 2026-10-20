Feature: Site Redirection

  # The www check curls www.<site> from the host, so the www name must resolve before the site is created.
  Scenario: no_www-no_ssl redirection works properly
    Given the hosts file maps "www.bh-site-type-php-91.test" to "127.0.0.1"
    When I run "bin/ee site create bh-site-type-php-91.test --type=php"
    Then the command should succeed
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/bh-site-type-php-91.test-redirect.conf" should exist
    And I wait until a GET request to "http://www.bh-site-type-php-91.test/" returns 301 within 30 seconds
    And Request on 'localhost' with header 'Host: www.bh-site-type-php-91.test' should contain following headers:
    | header                                     |
    | HTTP/1.1 301 Moved Permanently             |
    | Location: http://bh-site-type-php-91.test/ |

  Scenario: www-no_ssl redirection works properly
    Given the hosts file maps "bh-site-type-php-92.test" to "127.0.0.1"
    When I run "bin/ee site create www.bh-site-type-php-92.test --type=php"
    Then the command should succeed
    And I wait until a GET request to "http://bh-site-type-php-92.test/" returns 301 within 30 seconds
    And Request on 'localhost' with header 'Host: bh-site-type-php-92.test' should contain following headers:
    | header                                         |
    | HTTP/1.1 301 Moved Permanently                 |
    | Location: http://www.bh-site-type-php-92.test/ |

  Scenario: Without a resolvable www name no redirect is configured
    When I run "bin/ee site create bh-site-type-php-93.test --type=php"
    Then the command should succeed
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/bh-site-type-php-93.test-redirect.conf" should not exist

  Scenario: Deleting a site removes its redirect config
    Given the hosts file maps "www.bh-site-type-php-91.test" to "127.0.0.1"
    And a php site "bh-site-type-php-91.test" exists
    When I run "bin/ee site delete bh-site-type-php-91.test --yes"
    Then the command should succeed
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/bh-site-type-php-91.test-redirect.conf" should not exist
