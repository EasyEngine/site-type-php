Feature: Redis page cache of php sites (--cache, --with-local-redis) and ee site clean

  Scenario: --cache uses the global redis for the nginx page cache
    When I run "bin/ee site create bh-site-type-php-30.test --type=php --cache"
    Then the command should succeed
    And STDOUT should contain "| Cache         | Enabled"
    And the site "bh-site-type-php-30.test" should have the field "cache_host" set to "global-redis"
    And the site "bh-site-type-php-30.test" should have the field "cache_nginx_fullpage" set to "1"
    And the site "bh-site-type-php-30.test" should have the field "cache_nginx_browser" set to "1"
    And the site "bh-site-type-php-30.test" should have the field "cache_mysql_query" set to "1"
    And the docker container "services_global-redis_1" should be running

  Scenario: The global-redis cache config is in nginx and compose
    Given a php site "bh-site-type-php-30.test" created with "--cache" exists
    Then the file "/opt/easyengine/sites/bh-site-type-php-30.test/config/nginx/conf.d/main.conf" should contain "upstream redis"
    And the file "/opt/easyengine/sites/bh-site-type-php-30.test/config/nginx/conf.d/main.conf" should contain "server global-redis:6379;"
    And the file "/opt/easyengine/sites/bh-site-type-php-30.test/config/nginx/conf.d/main.conf" should contain "# Redis NGINX CONFIGURATION"
    And the file "/opt/easyengine/sites/bh-site-type-php-30.test/config/nginx/conf.d/main.conf" should contain:
      """
      set $key "bh-site-type-php-30.test:http$request_method$host$request_uri";
      """
    And the file "/opt/easyengine/sites/bh-site-type-php-30.test/config/nginx/conf.d/main.conf" should not contain "# PHP NGINX CONFIGURATION"
    And the file "/opt/easyengine/sites/bh-site-type-php-30.test/docker-compose.yml" should not contain "easyengine/redis"
    And the "nginx" service of site "bh-site-type-php-30.test" should be attached to the docker network "ee-global-backend-network"
    And the "php" service of site "bh-site-type-php-30.test" should be attached to the docker network "ee-global-backend-network"
    And the "redis" service of site "bh-site-type-php-30.test" should not exist
    And the site "bh-site-type-php-30.test" should have 3 running containers

  Scenario: Pages of a --cache site are stored in and served from the global redis
    Given a php site "bh-site-type-php-30.test" created with "--cache" exists
    When I send a GET request to "http://bh-site-type-php-30.test/"
    And I send a GET request to "http://bh-site-type-php-30.test/"
    Then the response code should be 200
    And the response header "X-SRCache-Fetch-Status" should contain "HIT"
    And the global redis should have keys matching "bh-site-type-php-30.test:*"
    And Check global redis cache for 'bh-site-type-php-30.test'

  Scenario: Requests with a query string bypass the page cache
    Given a php site "bh-site-type-php-30.test" created with "--cache" exists
    When I send a GET request to "http://bh-site-type-php-30.test/?bh=1"
    And I send a GET request to "http://bh-site-type-php-30.test/?bh=1"
    Then the response code should be 200
    And the response header "X-SRCache-Fetch-Status" should contain "BYPASS"

  Scenario: --cache --with-local-redis runs a redis container for the site
    When I run "bin/ee site create bh-site-type-php-31.test --type=php --cache --with-local-redis"
    Then the command should succeed
    And the site "bh-site-type-php-31.test" should have the field "cache_host" set to "redis"
    And the site "bh-site-type-php-31.test" should have the field "cache_nginx_fullpage" set to "1"
    And the "redis" service of site "bh-site-type-php-31.test" should be running
    And the "redis" service of site "bh-site-type-php-31.test" should use the pinned "easyengine/redis" image
    And the site "bh-site-type-php-31.test" should have 4 running containers
    And the file "/opt/easyengine/sites/bh-site-type-php-31.test/config/nginx/conf.d/main.conf" should contain "server redis:6379;"
    And the "nginx" service of site "bh-site-type-php-31.test" should not be attached to the docker network "ee-global-backend-network"

  Scenario: Pages of a local-redis site are cached in the site's own redis only
    Given a php site "bh-site-type-php-31.test" created with "--cache --with-local-redis" exists
    When I send a GET request to "http://bh-site-type-php-31.test/"
    And I send a GET request to "http://bh-site-type-php-31.test/"
    Then the response header "X-SRCache-Fetch-Status" should contain "HIT"
    And the redis service of site "bh-site-type-php-31.test" should have keys matching "bh-site-type-php-31.test:*"
    And the global redis should not have keys matching "bh-site-type-php-31.test:*"
    And Check local redis cache for 'bh-site-type-php-31.test'

  # Current behaviour: --with-local-redis only acts together with --cache.
  Scenario: --with-local-redis without --cache enables no cache
    When I run "bin/ee site create bh-site-type-php-32.test --type=php --with-local-redis"
    Then the command should succeed
    And the site "bh-site-type-php-32.test" should have an empty "cache_host"
    And the site "bh-site-type-php-32.test" should have the field "cache_nginx_fullpage" set to "0"
    And the "redis" service of site "bh-site-type-php-32.test" should not exist
    And the file "/opt/easyengine/sites/bh-site-type-php-32.test/config/nginx/conf.d/main.conf" should not contain "upstream redis"

  Scenario: Local db and global redis
    When I run "bin/ee site create bh-site-type-php-33.test --cache --type=php --local-db --with-db"
    Then the command should succeed
    And a GET request to "http://bh-site-type-php-33.test/" should return 200
    And the "db" service of site "bh-site-type-php-33.test" should be running
    And the "redis" service of site "bh-site-type-php-33.test" should not exist
    And the php service of site "bh-site-type-php-33.test" should be able to connect to the site database
    And the "nginx" service of site "bh-site-type-php-33.test" should be attached to the docker network "ee-global-backend-network"
    When I send a GET request to "http://bh-site-type-php-33.test/"
    And I send a GET request to "http://bh-site-type-php-33.test/"
    Then the response header "X-SRCache-Fetch-Status" should contain "HIT"
    And the global redis should have keys matching "bh-site-type-php-33.test:*"

  Scenario: Local redis and global db
    When I run "bin/ee site create bh-site-type-php-37.test --cache --type=php --with-db --with-local-redis"
    Then the command should succeed
    And a GET request to "http://bh-site-type-php-37.test/" should return 200
    And the "redis" service of site "bh-site-type-php-37.test" should be running
    And the "db" service of site "bh-site-type-php-37.test" should not exist
    And the php service of site "bh-site-type-php-37.test" should be able to connect to the site database
    When I send a GET request to "http://bh-site-type-php-37.test/"
    And I send a GET request to "http://bh-site-type-php-37.test/"
    Then the response header "X-SRCache-Fetch-Status" should contain "HIT"
    And the redis service of site "bh-site-type-php-37.test" should have keys matching "bh-site-type-php-37.test:*"
    And the global redis should not have keys matching "bh-site-type-php-37.test:*"

  Scenario: Local db and local redis
    When I run "bin/ee site create bh-site-type-php-38.test --cache --type=php --local-db --with-db --with-local-redis"
    Then the command should succeed
    And a GET request to "http://bh-site-type-php-38.test/" should return 200
    And the site "bh-site-type-php-38.test" should have 5 running containers
    And the site "bh-site-type-php-38.test" should have 11 docker volumes
    And the php service of site "bh-site-type-php-38.test" should be able to connect to the site database
    And Check local redis cache for 'bh-site-type-php-38.test'

  Scenario: ee site clean on a site without cache reports that no cache is enabled
    Given a php site "bh-site-type-php-34.test" exists
    When I run "timeout 120 bin/ee site clean bh-site-type-php-34.test"
    Then the return code should be 1
    And STDERR should contain "Site object cache is not enabled. Site page cache is not enabled."
    When I run "timeout 120 bin/ee site clean bh-site-type-php-34.test --proxy"
    Then the return code should be 1
    And STDERR should contain "Proxy cache is not enabled on site."
