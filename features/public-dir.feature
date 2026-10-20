Feature: Custom document root of php sites (--public-dir)

  Scenario: --public-dir serves the site from a sub directory of htdocs
    When I run "bin/ee site create bh-site-type-php-60.test --type=php --public-dir=public"
    Then the command should succeed
    And the site "bh-site-type-php-60.test" should have the field "site_container_fs_path" set to "/var/www/htdocs/public"
    And the file "/opt/easyengine/sites/bh-site-type-php-60.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs/public;"
    And the file "/opt/easyengine/sites/bh-site-type-php-60.test/app/htdocs/public/index.php" should contain "/opt/easyengine/sites/bh-site-type-php-60.test/app/htdocs/public"
    And the file "/opt/easyengine/sites/bh-site-type-php-60.test/app/htdocs/index.php" should not exist
    And the file "/opt/easyengine/sites/bh-site-type-php-60.test/app/htdocs/public/check.html" should not exist
    And a GET request to "http://bh-site-type-php-60.test/" should return 200
    And the response should contain "Congratulations!"

  Scenario: Files outside the public directory are not served
    Given a php site "bh-site-type-php-60.test" created with "--public-dir=public" exists
    When I write "<?php echo 'bh-outside';" to the file "/opt/easyengine/sites/bh-site-type-php-60.test/app/htdocs/outside.php"
    Then a GET request to "http://bh-site-type-php-60.test/outside.php" should return 404
    And the response should not contain "bh-outside"

  Scenario: A full container path with a trailing slash is normalised
    When I run "bin/ee site create bh-site-type-php-61.test --type=php --public-dir=/var/www/htdocs/web/"
    Then the command should succeed
    And the site "bh-site-type-php-61.test" should have the field "site_container_fs_path" set to "/var/www/htdocs/web"
    And the file "/opt/easyengine/sites/bh-site-type-php-61.test/app/htdocs/web/index.php" should exist
    And a GET request to "http://bh-site-type-php-61.test/" should return 200

  Scenario: A nested public directory is supported
    When I run "bin/ee site create bh-site-type-php-62.test --type=php --public-dir=app/public"
    Then the command should succeed
    And the site "bh-site-type-php-62.test" should have the field "site_container_fs_path" set to "/var/www/htdocs/app/public"
    And the file "/opt/easyengine/sites/bh-site-type-php-62.test/app/htdocs/app/public/index.php" should exist
    And a GET request to "http://bh-site-type-php-62.test/" should return 200

  Scenario: A public directory that tries to leave htdocs is sanitised
    When I run "bin/ee site create bh-site-type-php-63.test --type=php --public-dir=../../etc"
    Then the command should succeed
    And the site "bh-site-type-php-63.test" should have the field "site_container_fs_path" matching "#^/var/www/htdocs/[A-Za-z0-9_/-]+$#"
    And the site "bh-site-type-php-63.test" should have the field "site_container_fs_path" set to "/var/www/htdocs/etc"
    And the file "/opt/easyengine/sites/bh-site-type-php-63.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs/etc;"

  # PHPT-12: `[--public-dir]` is declared as a flag, so a bare --public-dir arrives as `true` and becomes the directory "1".
  # Either fix (reject the bare flag, or keep the default root) passes: only the "1" document root is asserted against.
  @known-bug
  Scenario: A bare --public-dir without a value does not create the document root "1"
    When I run "bin/ee site create bh-site-type-php-64.test --type=php --public-dir"
    Then the directory "/opt/easyengine/sites/bh-site-type-php-64.test/app/htdocs/1" should not exist

  Scenario: --public-dir works together with the redis page cache
    When I run "bin/ee site create bh-site-type-php-65.test --type=php --public-dir=public --cache"
    Then the command should succeed
    And the file "/opt/easyengine/sites/bh-site-type-php-65.test/config/nginx/conf.d/main.conf" should contain "root /var/www/htdocs/public;"
    And the file "/opt/easyengine/sites/bh-site-type-php-65.test/config/nginx/conf.d/main.conf" should contain "upstream redis"
    When I send a GET request to "http://bh-site-type-php-65.test/"
    And I send a GET request to "http://bh-site-type-php-65.test/"
    Then the response code should be 200
    And the response header "X-SRCache-Fetch-Status" should contain "HIT"
