Feature: SSL on php sites (create --ssl=self|custom|inherit|le, update --ssl)

  Scenario: Create a php site with a self-signed certificate
    When I run "bin/ee site create bh-site-type-php-80.test --type=php --ssl=self"
    Then the command should succeed
    And STDOUT should contain "| Site          | https://bh-site-type-php-80.test"
    And STDOUT should contain "| SSL           | Enabled"
    And STDOUT should contain "| SSL Wildcard  | Yes"
    And the site "bh-site-type-php-80.test" should have the field "site_ssl" set to "self"
    And the site "bh-site-type-php-80.test" should have the field "site_ssl_wildcard" set to "1"
    And the file "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-80.test.crt" should exist
    And the file "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-80.test.key" should exist
    And the hosts file should have an entry for "bh-site-type-php-80.test"

  Scenario: A self-signed php site is served over HTTPS and redirects HTTP
    Given a php site "bh-site-type-php-80.test" created with "--ssl=self" exists
    Then a GET request to "https://bh-site-type-php-80.test/" should return 200
    And the response should contain "Congratulations!"
    And a GET request to "http://bh-site-type-php-80.test/" should return 301
    And the response header "Location" should contain "https://bh-site-type-php-80.test/"
    And the certificate served for "bh-site-type-php-80.test" should cover "bh-site-type-php-80.test"
    And the certificate served for "bh-site-type-php-80.test" should cover "*.bh-site-type-php-80.test"
    And the certificate served for "bh-site-type-php-80.test" should match the file "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-80.test.crt"
    And the file "/opt/easyengine/sites/bh-site-type-php-80.test/docker-compose.yml" should not contain "HTTPS_METHOD=nohttps"

  Scenario: Create a php site with a custom certificate
    Given a self-signed certificate for "bh-site-type-php-81.test" exists in "/var/tmp/bh-site-type-php/certs"
    And the hosts file maps "bh-site-type-php-81.test" to "127.0.0.1"
    When I run "bin/ee site create bh-site-type-php-81.test --type=php --ssl=custom --ssl-key=/var/tmp/bh-site-type-php/certs/bh-site-type-php-81.test.key --ssl-crt=/var/tmp/bh-site-type-php/certs/bh-site-type-php-81.test.crt"
    Then the command should succeed
    And the site "bh-site-type-php-81.test" should have the field "site_ssl" set to "custom"
    And the files "/var/tmp/bh-site-type-php/certs/bh-site-type-php-81.test.crt" and "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-81.test.crt" should be identical
    And the files "/var/tmp/bh-site-type-php/certs/bh-site-type-php-81.test.key" and "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-81.test.key" should be identical
    And I wait until a GET request to "https://bh-site-type-php-81.test/" via "127.0.0.1" returns 200 within 60 seconds
    And the certificate served for "bh-site-type-php-81.test" should match the file "/var/tmp/bh-site-type-php/certs/bh-site-type-php-81.test.crt"

  Scenario: --ssl=custom needs both --ssl-key and --ssl-crt
    When I run "bin/ee site create bh-site-type-php-82.test --type=php --ssl=custom --ssl-crt=/var/tmp/bh-site-type-php/certs/none.crt"
    Then the return code should be 1
    And STDERR should contain "Pass --ssl-key and --ssl-crt for custom SSL"
    And the site "bh-site-type-php-82.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-82.test" should not exist

  Scenario: --ssl=custom with a missing certificate file fails
    When I run "bin/ee site create bh-site-type-php-83.test --type=php --ssl=custom --ssl-key=/var/tmp/bh-site-type-php/certs/none.key --ssl-crt=/var/tmp/bh-site-type-php/certs/none.crt"
    Then the return code should be 1
    And STDERR should contain "ssl-key OR ssl-crt path does not exist"
    And the site "bh-site-type-php-83.test" should not exist

  Scenario: --ssl=inherit without a parent site fails and rolls back
    When I run "bin/ee site create bh-site-type-php-84.test --type=php --ssl=inherit"
    Then the return code should be 1
    And STDERR should contain "Unable to find existing site: test"
    And the site "bh-site-type-php-84.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-84.test" should not exist

  Scenario: A subdomain site inherits the wildcard certificate of its parent
    Given a php site "bh-site-type-php-85.test" created with "--ssl=self" exists
    And the hosts file maps "sub.bh-site-type-php-85.test" to "127.0.0.1"
    When I run "bin/ee site create sub.bh-site-type-php-85.test --type=php --ssl=inherit"
    Then the command should succeed
    And STDOUT should contain "Inherited certs from parent"
    And the site "sub.bh-site-type-php-85.test" should have the field "site_ssl" set to "inherit"
    And I wait until a GET request to "https://sub.bh-site-type-php-85.test/" via "127.0.0.1" returns 200 within 60 seconds
    And the certificate served for "sub.bh-site-type-php-85.test" should cover "*.bh-site-type-php-85.test"

  Scenario: --ssl=inherit together with --wildcard is refused and rolled back
    Given a php site "bh-site-type-php-85.test" created with "--ssl=self" exists
    And the hosts file maps "sub2.bh-site-type-php-85.test" to "127.0.0.1"
    When I run "bin/ee site create sub2.bh-site-type-php-85.test --type=php --ssl=inherit --wildcard"
    Then the return code should be 1
    And STDERR should contain "Cannot use --wildcard with --ssl=inherit"
    And the site "sub2.bh-site-type-php-85.test" should not exist
    And the directory "/opt/easyengine/sites/sub2.bh-site-type-php-85.test" should not exist
    And the site "sub2.bh-site-type-php-85.test" should have 0 docker volumes
    And the "nginx" service of site "sub2.bh-site-type-php-85.test" should not exist
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/sub2.bh-site-type-php-85.test-redirect.conf" should not exist

  Scenario: --ssl with an unknown value fails and rolls back
    When I run "bin/ee site create bh-site-type-php-87.test --type=php --ssl=bogus --skip-status-check"
    Then the return code should be 1
    And STDERR should contain "Unrecognized value in --ssl flag: bogus"
    And the site "bh-site-type-php-87.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-87.test" should not exist
    And the site "bh-site-type-php-87.test" should have 0 docker volumes
    And the "nginx" service of site "bh-site-type-php-87.test" should not exist
    And the docker network "bh-site-type-php-87.test" should not exist

  Scenario: Enable and disable self-signed SSL on an existing php site
    Given a php site "bh-site-type-php-88.test" exists
    When I run "bin/ee site update bh-site-type-php-88.test --ssl=self"
    Then the command should succeed
    And STDOUT should contain "Success: Enabled SSL for bh-site-type-php-88.test"
    And the site "bh-site-type-php-88.test" should have the field "site_ssl" set to "self"
    And I wait until a GET request to "https://bh-site-type-php-88.test/" returns 200 within 60 seconds
    When I run "bin/ee site update bh-site-type-php-88.test --ssl=self"
    Then the return code should be 1
    And STDERR should contain "Error: SSL is already enabled on bh-site-type-php-88.test"
    When I run "bin/ee site update bh-site-type-php-88.test --ssl=off"
    Then the command should succeed
    And STDOUT should contain "Success: Disabled SSL for bh-site-type-php-88.test"
    And the site "bh-site-type-php-88.test" should have an empty "site_ssl"
    And the file "/opt/easyengine/sites/bh-site-type-php-88.test/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"
    And I wait until a GET request to "http://bh-site-type-php-88.test/" returns 200 within 60 seconds
    When I run "bin/ee site update bh-site-type-php-88.test --ssl=off"
    Then the return code should be 1
    And STDERR should contain "Error: SSL is already disabled on bh-site-type-php-88.test"

  # Site 80 is SSL-on with site_ssl_wildcard=1 (from create --ssl=self), the only state that reaches this check.
  Scenario: --ssl=off with --wildcard is refused
    Given a php site "bh-site-type-php-80.test" created with "--ssl=self" exists
    When I run "bin/ee site update bh-site-type-php-80.test --ssl=off --wildcard"
    Then the return code should be 1
    And STDERR should contain "You cannot use --wildcard flag with --ssl=off"
    And the site "bh-site-type-php-80.test" should have the field "site_ssl" set to "self"

  Scenario: Switching a normal SSL site to wildcard SSL is refused
    Given a php site "bh-site-type-php-88.test" exists
    When I run "bin/ee site update bh-site-type-php-88.test --ssl=self --wildcard"
    Then the return code should be 1
    And STDERR should contain "Update from normal SSL to wildcard SSL is not supported yet."

  # PHPT-10 / SC-3 (fixed in site-command fix/proxy-cache-rollback-and-le-alias): update_ssl() refused --ssl=off for self-signed creates, which store site_ssl_wildcard=1.
  Scenario: SSL of a site created with --ssl=self can be disabled
    Given a php site "bh-site-type-php-89.test" created with "--ssl=self" exists
    When I run "bin/ee site update bh-site-type-php-89.test --ssl=off"
    Then the command should succeed
    And the site "bh-site-type-php-89.test" should have an empty "site_ssl"

  @le
  Scenario: Create and delete a php site with a Let's Encrypt certificate
    Given the domain "bh-site-type-php-1.ee4-test.rt.gw" resolves publicly
    And the ee config has a "le-mail" value
    And the site "bh-site-type-php-1.ee4-test.rt.gw" does not exist
    When I run "bin/ee site create bh-site-type-php-1.ee4-test.rt.gw --type=php --ssl=le"
    Then the command should succeed
    And the site "bh-site-type-php-1.ee4-test.rt.gw" should have the field "site_ssl" set to "le"
    And the site "bh-site-type-php-1.ee4-test.rt.gw" should have the field "site_ssl_wildcard" set to "0"
    And the hosts file should not have an entry for "bh-site-type-php-1.ee4-test.rt.gw"
    And I wait until a GET request to "https://bh-site-type-php-1.ee4-test.rt.gw/" returns 200 within 60 seconds
    And a GET request to "https://bh-site-type-php-1.ee4-test.rt.gw/" with certificate verification should return 200
    And the response should contain "Congratulations!"
    And the certificate served for "bh-site-type-php-1.ee4-test.rt.gw" should be issued by the ACME CA
    And a GET request to "http://bh-site-type-php-1.ee4-test.rt.gw/" should return 301
    When I run "bin/ee site delete bh-site-type-php-1.ee4-test.rt.gw --yes"
    Then the command should succeed
    And STDOUT should contain "Revoking certificate."
    And the file "/opt/easyengine/services/nginx-proxy/certs/bh-site-type-php-1.ee4-test.rt.gw.crt" should not exist
    And the site "bh-site-type-php-1.ee4-test.rt.gw" should not exist
