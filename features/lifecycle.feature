Feature: info, list, enable, disable, refresh, restart, reload, proxy cache and delete of php sites

  Scenario: site info shows the php site details
    Given a php site "bh-site-type-php-100.test" exists
    When I run "bin/ee site info bh-site-type-php-100.test"
    Then the command should succeed
    And STDOUT should contain "| Site          | http://bh-site-type-php-100.test"
    And STDOUT should contain "| Site Root     | /opt/easyengine/sites/bh-site-type-php-100.test"
    And STDOUT should contain "| Alias Domains | None"
    And STDOUT should contain "| E-Mail        | admin@bh-site-type-php-100.test"
    And STDOUT should contain "| SSL           | Not Enabled"
    And STDOUT should contain "| Cache         | None"
    And STDOUT should not contain "SSL Wildcard"
    And STDOUT should not contain "DB Name"

  Scenario: site info --format=json dumps the site row
    Given a php site "bh-site-type-php-100.test" exists
    When I run "bin/ee site info bh-site-type-php-100.test --format=json"
    Then the command should succeed
    And STDOUT should be valid json
    And the JSON in STDOUT should have "site_url" set to "bh-site-type-php-100.test"
    And the JSON in STDOUT should have "site_type" set to "php"
    And the JSON in STDOUT should have "app_sub_type" set to "php"
    And the JSON in STDOUT should have "php_version" set to "8.4"
    And the JSON in STDOUT should have "site_enabled" set to "1"
    And the JSON in STDOUT should have "site_container_fs_path" set to "/var/www/htdocs"

  Scenario: site info of a php site with a database and cache lists them
    Given a php site "bh-site-type-php-101.test" created with "--with-db --cache --dbname=bh_stp_db101 --dbuser=bh_stp_user101 --dbpass=Bh-Stp-Pass-101" exists
    When I run "bin/ee site info bh-site-type-php-101.test"
    Then the command should succeed
    And STDOUT should match:
      """
      /\|\s*DB Host\s*\|\s*global-db\s*\|.*\|\s*DB Name\s*\|\s*bh_stp_db101\s*\|.*\|\s*DB User\s*\|\s*bh_stp_user101\s*\|.*\|\s*DB Password\s*\|\s*Bh-Stp-Pass-101\s*\|/s
      """
    And STDOUT should contain "| Cache         | Enabled"
    When I run "bin/ee site info bh-site-type-php-101.test --format=json"
    Then the JSON in STDOUT should have "db_name" set to "bh_stp_db101"
    And the JSON in STDOUT should have "cache_host" set to "global-redis"

  # The site root, not app/htdocs: app is a symlink into the docker volume, and get_site_name() reads the physical `pwd`.
  Scenario: site info works from inside the site directory
    Given a php site "bh-site-type-php-100.test" exists
    When I change to the directory "/opt/easyengine/sites/bh-site-type-php-100.test" and run "bin/ee site info"
    Then the command should succeed
    And STDOUT should contain "| Site          | http://bh-site-type-php-100.test"

  Scenario: site info of an unknown site fails
    When I run "bin/ee site info bh-site-type-php-197.test"
    Then the return code should be 1
    And STDERR should contain "Could not find the site you wish to run site info command on."

  Scenario: site list shows php sites with their status
    Given a php site "bh-site-type-php-100.test" exists
    When I run "bin/ee site list --format=json"
    Then the command should succeed
    And the JSON list in STDOUT should contain an item with "site" set to "bh-site-type-php-100.test"
    And the JSON list in STDOUT should contain an item with "site" set to "bh-site-type-php-100.test" and "status" set to "enabled"
    When I run "bin/ee site list --format=text"
    Then STDOUT should contain "bh-site-type-php-100.test"

  Scenario: site list --enabled and --disabled filter php sites
    Given a php site "bh-site-type-php-100.test" exists
    And a php site "bh-site-type-php-102.test" exists
    And the site "bh-site-type-php-102.test" is disabled
    When I run "bin/ee site list --enabled --format=json"
    Then the JSON list in STDOUT should contain an item with "site" set to "bh-site-type-php-100.test"
    And the JSON list in STDOUT should not contain an item with "site" set to "bh-site-type-php-102.test"
    When I run "bin/ee site list --disabled --format=json"
    Then the JSON list in STDOUT should contain an item with "site" set to "bh-site-type-php-102.test"
    And the JSON list in STDOUT should not contain an item with "site" set to "bh-site-type-php-100.test"

  Scenario: Disable a php site
    Given a php site "bh-site-type-php-102.test" exists
    And I run "bin/ee site enable bh-site-type-php-102.test --force"
    When I run "bin/ee site disable bh-site-type-php-102.test"
    Then the command should succeed
    And STDOUT should contain "Success: Site bh-site-type-php-102.test disabled."
    And the site "bh-site-type-php-102.test" should be disabled
    And the "php" service of site "bh-site-type-php-102.test" should not exist
    And the "nginx" service of site "bh-site-type-php-102.test" should not exist
    And the site "bh-site-type-php-102.test" should have 8 docker volumes
    And the file "/opt/easyengine/sites/bh-site-type-php-102.test/app/htdocs/index.php" should exist
    And I wait until a GET request to "http://bh-site-type-php-102.test/" no longer returns 200 within 60 seconds

  Scenario: Disabling a disabled php site is harmless
    Given a php site "bh-site-type-php-102.test" exists
    And the site "bh-site-type-php-102.test" is disabled
    When I run "bin/ee site disable bh-site-type-php-102.test"
    Then the command should succeed
    And the site "bh-site-type-php-102.test" should be disabled

  Scenario: site info still works for a disabled php site
    Given a php site "bh-site-type-php-102.test" exists
    And the site "bh-site-type-php-102.test" is disabled
    When I run "bin/ee site info bh-site-type-php-102.test"
    Then the command should succeed
    And STDOUT should contain "| Site          | http://bh-site-type-php-102.test"

  Scenario: Enable a disabled php site
    Given a php site "bh-site-type-php-102.test" exists
    And the site "bh-site-type-php-102.test" is disabled
    When I run "bin/ee site enable bh-site-type-php-102.test"
    Then the command should succeed
    And STDOUT should contain "Success: Site bh-site-type-php-102.test enabled."
    And STDOUT should contain "Success: Post enable configurations complete."
    And the site "bh-site-type-php-102.test" should be enabled
    And the "php" service of site "bh-site-type-php-102.test" should be running
    And the "nginx" service of site "bh-site-type-php-102.test" should be running
    And the "postfix" service of site "bh-site-type-php-102.test" should be running
    And the "mailhog" service of site "bh-site-type-php-102.test" should not be running
    And I wait until a GET request to "http://bh-site-type-php-102.test/" returns 200 within 60 seconds

  Scenario: Enabling an enabled php site is refused unless forced
    Given a php site "bh-site-type-php-102.test" exists
    And I run "bin/ee site enable bh-site-type-php-102.test --force"
    When I run "bin/ee site enable bh-site-type-php-102.test"
    Then the return code should be 1
    And STDERR should contain "Error: bh-site-type-php-102.test is already enabled!"
    When I run "bin/ee site enable bh-site-type-php-102.test --force"
    Then the command should succeed
    And STDOUT should contain "Success: Site bh-site-type-php-102.test enabled."

  Scenario: site refresh regenerates the docker-compose file of a php site
    Given a php site "bh-site-type-php-106.test" exists
    And I append "# bh-compose-marker" to the file "/opt/easyengine/sites/bh-site-type-php-106.test/docker-compose.yml"
    When I run "bin/ee site refresh bh-site-type-php-106.test"
    Then the command should succeed
    And the file "/opt/easyengine/sites/bh-site-type-php-106.test/docker-compose.yml" should not contain "# bh-compose-marker"
    And the docker-compose file of site "bh-site-type-php-106.test" should use the pinned "easyengine/php8.4" image
    And I wait until a GET request to "http://bh-site-type-php-106.test/" returns 200 within 60 seconds

  Scenario: site restart restarts nginx, php and the local db, not postfix
    Given a php site "bh-site-type-php-103.test" created with "--with-db --local-db" exists
    And I note the start time of the "nginx" service of site "bh-site-type-php-103.test"
    And I note the start time of the "php" service of site "bh-site-type-php-103.test"
    And I note the start time of the "db" service of site "bh-site-type-php-103.test"
    And I note the start time of the "postfix" service of site "bh-site-type-php-103.test"
    When I run "bin/ee site restart bh-site-type-php-103.test"
    Then the command should succeed
    And STDOUT should contain "Restarting nginx"
    And STDOUT should contain "Restarting php"
    And STDOUT should contain "Restarting db"
    And the "nginx" service of site "bh-site-type-php-103.test" should have been restarted
    And the "php" service of site "bh-site-type-php-103.test" should have been restarted
    And the "db" service of site "bh-site-type-php-103.test" should have been restarted
    And the "postfix" service of site "bh-site-type-php-103.test" should not have been restarted
    And I wait until a GET request to "http://bh-site-type-php-103.test/" returns 200 within 60 seconds

  Scenario: site restart --php restarts only php
    Given a php site "bh-site-type-php-103.test" created with "--with-db --local-db" exists
    And I note the start time of the "nginx" service of site "bh-site-type-php-103.test"
    And I note the start time of the "php" service of site "bh-site-type-php-103.test"
    When I run "bin/ee site restart bh-site-type-php-103.test --php"
    Then the command should succeed
    And STDOUT should contain "Restarting php"
    And STDOUT should not contain "Restarting nginx"
    And the "php" service of site "bh-site-type-php-103.test" should have been restarted
    And the "nginx" service of site "bh-site-type-php-103.test" should not have been restarted

  Scenario: site restart --db restarts the local db
    Given a php site "bh-site-type-php-103.test" created with "--with-db --local-db" exists
    And I note the start time of the "db" service of site "bh-site-type-php-103.test"
    When I run "bin/ee site restart bh-site-type-php-103.test --db"
    Then the command should succeed
    And STDOUT should contain "Restarting db"
    And the "db" service of site "bh-site-type-php-103.test" should have been restarted

  Scenario: site reload reloads nginx and php-fpm without restarting containers
    Given a php site "bh-site-type-php-100.test" exists
    And I note the start time of the "nginx" service of site "bh-site-type-php-100.test"
    And I note the start time of the "php" service of site "bh-site-type-php-100.test"
    When I run "bin/ee site reload bh-site-type-php-100.test"
    Then the command should succeed
    And STDOUT should contain "Reloading nginx"
    And STDOUT should contain "Reloading php"
    And the "nginx" service of site "bh-site-type-php-100.test" should not have been restarted
    And the "php" service of site "bh-site-type-php-100.test" should not have been restarted
    And I wait until a GET request to "http://bh-site-type-php-100.test/" returns 200 within 30 seconds

  Scenario: site reload --php and --nginx reload a single service
    Given a php site "bh-site-type-php-100.test" exists
    When I run "bin/ee site reload bh-site-type-php-100.test --php"
    Then the command should succeed
    And STDOUT should contain "Reloading php"
    And STDOUT should not contain "Reloading nginx"
    When I run "bin/ee site reload bh-site-type-php-100.test --nginx"
    Then the command should succeed
    And STDOUT should contain "Reloading nginx"
    And STDOUT should not contain "Reloading php"

  Scenario: site reload of a disabled php site is refused
    Given a php site "bh-site-type-php-102.test" exists
    And the site "bh-site-type-php-102.test" is disabled
    When I run "bin/ee site reload bh-site-type-php-102.test"
    Then the return code should be 1
    And STDERR should contain "Error: Site bh-site-type-php-102.test is not enabled. Use `ee site enable bh-site-type-php-102.test` to enable it."

  # update --proxy-cache restarts the global nginx-proxy container, so it briefly affects every site on the host.
  @destructive
  Scenario: Turn the proxy cache of a php site with an alias domain on and off
    Given a php site "bh-site-type-php-104.test" created with "--alias-domains=bh-site-type-php-104-a.test" exists
    When I run "bin/ee site update bh-site-type-php-104.test --proxy-cache=on"
    Then the command should succeed
    And STDOUT should contain "Success: Proxy cache update on site bh-site-type-php-104.test completed."
    And the site "bh-site-type-php-104.test" should have the field "proxy_cache" set to "on"
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/bh-site-type-php-104.test.conf" should exist
    And the file "/opt/easyengine/services/nginx-proxy/vhost.d/bh-site-type-php-104.test_location" should exist
    And the file "/opt/easyengine/services/nginx-proxy/vhost.d/bh-site-type-php-104-a.test_location" should exist
    And I wait until a GET request to "http://bh-site-type-php-104.test/" returns 200 within 90 seconds
    When I run "bin/ee site update bh-site-type-php-104.test --proxy-cache=on"
    Then the return code should be 1
    And STDERR should contain "Site bh-site-type-php-104.test already has proxy cache: on"
    When I run "bin/ee site update bh-site-type-php-104.test --proxy-cache=off"
    Then the command should succeed
    And the site "bh-site-type-php-104.test" should have the field "proxy_cache" set to "off"
    And the file "/opt/easyengine/services/nginx-proxy/conf.d/bh-site-type-php-104.test.conf" should not exist
    And the file "/opt/easyengine/services/nginx-proxy/vhost.d/bh-site-type-php-104.test_location" should not exist
    And the file "/opt/easyengine/services/nginx-proxy/vhost.d/bh-site-type-php-104-a.test_location" should not exist
    And I wait until a GET request to "http://bh-site-type-php-104.test/" returns 200 within 90 seconds
    And the docker container "services_global-nginx-proxy_1" should be running

  Scenario: Delete a php site and everything it owns
    Given a php site "bh-site-type-php-105.test" exists
    When I run "bin/ee site delete bh-site-type-php-105.test --yes"
    Then the command should succeed
    And STDOUT should contain "Site bh-site-type-php-105.test deleted."
    And STDERR should be empty
    And The 'bh-site-type-php-105.test' db entry should be removed
    And The 'bh-site-type-php-105.test' webroot should be removed
    And Following containers of site 'bh-site-type-php-105.test' should be removed:
      | container  |
      | nginx      |
      | php        |
      | postfix    |
      | mailhog    |
    And the site "bh-site-type-php-105.test" should have 0 docker volumes
    And the docker network "bh-site-type-php-105.test" should not exist
    And the hosts file should not have an entry for "bh-site-type-php-105.test"

  Scenario: Deleting a php site twice fails the second time
    Given the site "bh-site-type-php-105.test" does not exist
    When I run "bin/ee site delete bh-site-type-php-105.test --yes"
    Then the return code should be 1
    And STDERR should contain "Error: Site bh-site-type-php-105.test does not exist."
