Feature: Create a plain php site

  Scenario: Create a php site with default options
    When I run "bin/ee site create bh-site-type-php-1.test --type=php"
    Then the command should succeed
    And STDOUT should contain "Creating PHP site bh-site-type-php-1.test"
    And STDOUT should contain "Success: Configuration files copied."
    And STDOUT should contain "Checking and verifying site-up status"
    And STDOUT should contain "Site entry created."
    And STDOUT should contain "| Site          | http://bh-site-type-php-1.test"
    And STDOUT should contain "| Site Root     | /opt/easyengine/sites/bh-site-type-php-1.test"
    And STDOUT should not contain "DB Host"
    And the site "bh-site-type-php-1.test" should exist

  Scenario: The site row holds the php defaults
    Given a php site "bh-site-type-php-1.test" exists
    Then the site "bh-site-type-php-1.test" should be enabled
    And the site "bh-site-type-php-1.test" should have the field "site_type" set to "php"
    And the site "bh-site-type-php-1.test" should have the field "app_sub_type" set to "php"
    And the site "bh-site-type-php-1.test" should have the field "php_version" set to "8.4"
    And the site "bh-site-type-php-1.test" should have the field "site_fs_path" set to "/opt/easyengine/sites/bh-site-type-php-1.test"
    And the site "bh-site-type-php-1.test" should have the field "site_container_fs_path" set to "/var/www/htdocs"
    And the site "bh-site-type-php-1.test" should have the field "alias_domains" set to "bh-site-type-php-1.test"
    And the site "bh-site-type-php-1.test" should have the field "app_admin_email" set to "admin@bh-site-type-php-1.test"
    And the site "bh-site-type-php-1.test" should have the field "cache_nginx_fullpage" set to "0"
    And the site "bh-site-type-php-1.test" should have the field "cache_nginx_browser" set to "0"
    And the site "bh-site-type-php-1.test" should have the field "cache_mysql_query" set to "0"
    And the site "bh-site-type-php-1.test" should have the field "site_ssl_wildcard" set to "0"
    And the site "bh-site-type-php-1.test" should have the field "proxy_cache" set to "off"
    And the site "bh-site-type-php-1.test" should have an empty "site_ssl"
    And the site "bh-site-type-php-1.test" should have an empty "cache_host"
    And the site "bh-site-type-php-1.test" should have an empty "db_host"
    And the site "bh-site-type-php-1.test" should have an empty "db_name"
    And the site "bh-site-type-php-1.test" should have an empty "db_user"
    And the site "bh-site-type-php-1.test" should have an empty "db_password"

  Scenario: The site files are generated from the php templates
    Given a php site "bh-site-type-php-1.test" exists
    Then the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should exist
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/.env" should contain "VIRTUAL_HOST=bh-site-type-php-1.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/.env" should not contain "MYSQL_ROOT_PASSWORD"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/conf.d/main.conf" should contain "server_name bh-site-type-php-1.test;"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/conf.d/main.conf" should contain "server bh-site-type-php-1.test_php:9000;"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs;"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/conf.d/main.conf" should contain "# PHP NGINX CONFIGURATION"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/conf.d/main.conf" should not contain "upstream redis"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/custom/user.conf" should exist
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/nginx/custom/admin-tools.conf" should exist
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/config/php/php/conf.d/custom.ini" should contain "sendmail_path = /usr/bin/msmtp -t"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/index.php" should contain "Congratulations! Your PHP site with EasyEngine is working perfectly."
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/index.php" should contain "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs"
    And the directory "/opt/easyengine/sites/bh-site-type-php-1.test/app/html" should not exist
    And the path "/opt/easyengine/sites/bh-site-type-php-1.test/app" should be a symlink
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/check.html" should not exist

  Scenario: The compose file has the php services and no db or redis
    Given a php site "bh-site-type-php-1.test" exists
    Then the docker-compose file of site "bh-site-type-php-1.test" should use the pinned "easyengine/php8.4" image
    And the docker-compose file of site "bh-site-type-php-1.test" should use the pinned "easyengine/nginx" image
    And the docker-compose file of site "bh-site-type-php-1.test" should use the pinned "easyengine/postfix" image
    And the docker-compose file of site "bh-site-type-php-1.test" should use the pinned "easyengine/mailhog" image
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should contain "VIRTUAL_HOST=${VIRTUAL_HOST}"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should contain "- ${VIRTUAL_HOST}_php"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should not contain "easyengine/mariadb"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should not contain "easyengine/redis"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should not contain "CERT_NAME"
    And the file "/opt/easyengine/sites/bh-site-type-php-1.test/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"

  Scenario: The site containers, volumes and network are up
    Given a php site "bh-site-type-php-1.test" exists
    Then the "php" service of site "bh-site-type-php-1.test" should be running
    And the "nginx" service of site "bh-site-type-php-1.test" should be running
    And the "postfix" service of site "bh-site-type-php-1.test" should be running
    And the "mailhog" service of site "bh-site-type-php-1.test" should not be running
    And the site "bh-site-type-php-1.test" should have 3 running containers
    And the site "bh-site-type-php-1.test" should have 8 docker volumes
    And the docker network "bh-site-type-php-1.test" should exist
    And the "php" service of site "bh-site-type-php-1.test" should use the pinned "easyengine/php8.4" image
    And the "nginx" service of site "bh-site-type-php-1.test" should be attached to the docker network "ee-global-frontend-network"
    And the "php" service of site "bh-site-type-php-1.test" should not be attached to the docker network "ee-global-frontend-network"
    And the "php" service of site "bh-site-type-php-1.test" should have the restart policy "always"
    And the php service of site "bh-site-type-php-1.test" should run PHP "8.4"

  Scenario: The site is reachable over HTTP and runs PHP
    Given a php site "bh-site-type-php-1.test" exists
    Then the hosts file should have an entry for "bh-site-type-php-1.test"
    And a GET request to "http://bh-site-type-php-1.test/" should return 200
    And the response should contain "Congratulations! Your PHP site with EasyEngine is working perfectly."
    And the response should not have the header "X-SRCache-Fetch-Status"
    When I write "<?php echo 'bh-php-' . (6 * 7);" to the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/bh.php"
    Then a GET request to "http://bh-site-type-php-1.test/bh.php" should return 200
    And the response should contain "bh-php-42"
    And a GET request to "http://bh-site-type-php-1.test/no-such-file.php" should return 404
    And a GET request to "http://bh-site-type-php-1.test/some/pretty/url" should return 200
    And the response should contain "Congratulations!"

  Scenario: nginx denies hidden and backup files of a php site
    Given a php site "bh-site-type-php-1.test" exists
    When I write "secret" to the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/.env"
    And I write "secret" to the file "/opt/easyengine/sites/bh-site-type-php-1.test/app/htdocs/dump.sql"
    Then a GET request to "http://bh-site-type-php-1.test/.env" should return 403
    And a GET request to "http://bh-site-type-php-1.test/dump.sql" should return 403

  Scenario: The php-fpm status and ping endpoints are wired
    Given a php site "bh-site-type-php-1.test" exists
    Then a GET request to "http://bh-site-type-php-1.test/ee-admin/ping" should return 200
    And the response should contain "pong"
    And a GET request to "http://bh-site-type-php-1.test/ee-admin/status" should return 200
    And the response should contain "pool:"
    And a GET request to "http://bh-site-type-php-1.test/ee-admin/nginx_status" should return 200
    And the response should contain "Active connections"

  Scenario: Creating the same site twice fails and leaves the first one intact
    Given a php site "bh-site-type-php-1.test" exists
    When I run "bin/ee site create bh-site-type-php-1.test --type=php"
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Site bh-site-type-php-1.test already exists. If you want to re-create it please delete the older one using:
      `ee site delete bh-site-type-php-1.test`
      """
    And a GET request to "http://bh-site-type-php-1.test/" should return 200

  Scenario: The site name is normalised: scheme and trailing slash stripped, lowercased
    When I run "bin/ee site create http://BH-Site-Type-PHP-2.TEST/ --type=php"
    Then the command should succeed
    And the site "bh-site-type-php-2.test" should exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-2.test" should exist
    And a GET request to "http://bh-site-type-php-2.test/" should return 200

  Scenario: A site name without a dot gets the .test TLD
    When I run "bin/ee site create bh-site-type-php-3 --type=php"
    Then the command should succeed
    And the site "bh-site-type-php-3.test" should exist

  Scenario: --admin-email is stored and shown, --skip-status-check skips the HTTP probe
    When I run "bin/ee site create bh-site-type-php-4.test --type=php --admin-email=ops@bh-site-type-php-4.test --skip-status-check"
    Then the command should succeed
    And STDOUT should not contain "Checking and verifying site-up status"
    And STDOUT should contain "| E-Mail        | ops@bh-site-type-php-4.test"
    And the site "bh-site-type-php-4.test" should have the field "app_admin_email" set to "ops@bh-site-type-php-4.test"
    When I wait until a GET request to "http://bh-site-type-php-4.test/" returns 200 within 60 seconds

  Scenario: An unknown flag is rejected before anything is created
    When I run "bin/ee site create bh-site-type-php-9.test --type=php --bh-nope"
    Then the command should fail
    And STDERR should contain "unknown --bh-nope parameter"
    And the site "bh-site-type-php-9.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-9.test" should not exist

  Scenario Outline: An unsupported --php value is rejected before anything is created
    When I run "bin/ee site create bh-site-type-php-9.test --type=php --php=<version>"
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'php'"
    And the site "bh-site-type-php-9.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-9.test" should not exist

    Examples:
      | version |
      | 5.5     |
      | 7.1     |
      | 9.0     |
      | abc     |

  # EE3 compat: --mysql maps to --type=php --with-db (Site_Command::convert_old_args_to_new_args).
  Scenario: The legacy --mysql flag creates a php site with a database
    When I run "bin/ee site create bh-site-type-php-7.test --mysql"
    Then the command should succeed
    And the site "bh-site-type-php-7.test" should have the field "site_type" set to "php"
    And the site "bh-site-type-php-7.test" should have the field "app_sub_type" set to "mysql"
    And the site "bh-site-type-php-7.test" should have the field "db_host" set to "global-db"

  # Regression check (fixed in v4.13.2 by EasyEngine/site-type-php#117 and EasyEngine/site-command#509): the site name used to reach shell commands unescaped (volume commands run from the core root). The benign payload survives the lowercasing and writes ./bhstpsec1 there, which the scenario cleanup removes.
  @security
  Scenario: A site name with shell syntax is rejected and never executed
    When I run "bin/ee site create 'bh-site-type-php-5$(id>bhstpsec1).test' --type=php"
    Then the command should fail
    And STDERR should contain "Invalid site name"
    And the file "bhstpsec1" should not exist

  # Core's EE_DOCKER::get_docker_style_prefix() drops "." and "-" from volume names, so both names below map to the prefix bhsitetypephp27test; existing sites keep their volume names, so the second create is refused (EasyEngine/site-command#503).
  Scenario: A site name whose docker volumes collide with an existing site is refused
    Given a php site "bh-site-type-php-2-7.test" exists
    When I run "bin/ee site create bh-site-type-php-27.test --type=php"
    Then the command should fail
    And STDERR should contain "Site bh-site-type-php-27.test would share docker volumes (bhsitetypephp27test_*) with the existing site bh-site-type-php-2-7.test."
    And the site "bh-site-type-php-27.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-27.test" should not exist
    And the site "bh-site-type-php-2-7.test" should have 8 docker volumes
    And a GET request to "http://bh-site-type-php-2-7.test/" should return 200
    And the response should contain "/opt/easyengine/sites/bh-site-type-php-2-7.test/app/htdocs"
