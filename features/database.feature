Feature: Database options of php sites (--with-db, --local-db, --dbhost, --dbname, --dbuser, --dbpass)

  Scenario: --with-db creates a database and user on the global db
    When I run "bin/ee site create bh-site-type-php-10.test --type=php --with-db"
    Then the command should succeed
    And STDOUT should match:
      """
      /\|\s*DB Host\s*\|\s*global-db\s*\|/
      """
    And STDOUT should match:
      """
      /\|\s*DB Name\s*\|\s*bh_site_type_php_10_test\s*\|/
      """
    And STDOUT should not contain "DB Root Password"
    And the site "bh-site-type-php-10.test" should have the field "app_sub_type" set to "mysql"
    And the site "bh-site-type-php-10.test" should have the field "db_host" set to "global-db"
    And the site "bh-site-type-php-10.test" should have the field "db_port" set to "3306"
    And the site "bh-site-type-php-10.test" should have the field "db_name" set to "bh_site_type_php_10_test"
    And the site "bh-site-type-php-10.test" should have the field "db_user" matching "/^bh-site-type-php-10\.test-[A-Za-z0-9]{6}$/"
    And the site "bh-site-type-php-10.test" should have a non-empty "db_password"
    And the site "bh-site-type-php-10.test" should have an empty "db_root_password"
    And the global database "bh_site_type_php_10_test" should exist

  Scenario: A global-db php site connects to its database and has no db container
    Given a php site "bh-site-type-php-10.test" created with "--with-db" exists
    Then the php service of site "bh-site-type-php-10.test" should be able to connect to the site database
    And the "db" service of site "bh-site-type-php-10.test" should not exist
    And the site "bh-site-type-php-10.test" should have 3 running containers
    And the site "bh-site-type-php-10.test" should have 8 docker volumes
    And the "php" service of site "bh-site-type-php-10.test" should be attached to the docker network "ee-global-backend-network"
    And the file "/opt/easyengine/sites/bh-site-type-php-10.test/docker-compose.yml" should not contain "easyengine/mariadb"
    And the file "/opt/easyengine/sites/bh-site-type-php-10.test/.env" should not contain "MYSQL_ROOT_PASSWORD"
    And a GET request to "http://bh-site-type-php-10.test/" should return 200

  # DB-1: the MariaDB 11.8 client in php8.2+ images demands TLS, which global-db does not offer.
  @known-bug
  Scenario: The mysql CLI inside the php container reaches the global db
    Given a php site "bh-site-type-php-10.test" created with "--with-db" exists
    Then Check global mysql database connection for 'bh-site-type-php-10.test'

  Scenario: --dbname, --dbuser and --dbpass are used for the global db
    When I run "bin/ee site create bh-site-type-php-11.test --type=php --with-db --dbname=bh_stp_db11 --dbuser=bh_stp_user11 --dbpass=Bh-Stp-Pass-11"
    Then the command should succeed
    And the site "bh-site-type-php-11.test" should have the field "db_name" set to "bh_stp_db11"
    And the site "bh-site-type-php-11.test" should have the field "db_user" set to "bh_stp_user11"
    And the site "bh-site-type-php-11.test" should have the field "db_password" set to "Bh-Stp-Pass-11"
    And the global database "bh_stp_db11" should exist
    And the global database user "bh_stp_user11" should exist
    And the php service of site "bh-site-type-php-11.test" should be able to connect to the site database

  Scenario: --dbhost=global-db is the same as the default global db
    When I run "bin/ee site create bh-site-type-php-15.test --type=php --with-db --dbhost=global-db"
    Then the command should succeed
    And the site "bh-site-type-php-15.test" should have the field "db_host" set to "global-db"
    And the site "bh-site-type-php-15.test" should have the field "db_name" set to "bh_site_type_php_15_test"
    And the global database "bh_site_type_php_15_test" should exist
    And the php service of site "bh-site-type-php-15.test" should be able to connect to the site database

  Scenario: --local-db runs a MariaDB container for the site
    When I run "bin/ee site create bh-site-type-php-12.test --type=php --with-db --local-db"
    Then the command should succeed
    And STDOUT should match:
      """
      /\|\s*DB Host\s*\|\s*db\s*\|/
      """
    And STDOUT should contain "DB Root Password"
    And the site "bh-site-type-php-12.test" should have the field "db_host" set to "db"
    And the site "bh-site-type-php-12.test" should have the field "db_name" set to "bh_site_type_php_12_test"
    And the site "bh-site-type-php-12.test" should have a non-empty "db_root_password"
    And the site "bh-site-type-php-12.test" should have a non-empty "db_password"
    And the global database "bh_site_type_php_12_test" should not exist

  Scenario: A local-db php site has its db service, env and volumes
    Given a php site "bh-site-type-php-12.test" created with "--with-db --local-db" exists
    Then the "db" service of site "bh-site-type-php-12.test" should be running
    And the "db" service of site "bh-site-type-php-12.test" should use the pinned "easyengine/mariadb" image
    And the "db" service of site "bh-site-type-php-12.test" should have the restart policy "always"
    And the site "bh-site-type-php-12.test" should have 4 running containers
    And the site "bh-site-type-php-12.test" should have 11 docker volumes
    And the file "/opt/easyengine/sites/bh-site-type-php-12.test/.env" should contain "MYSQL_ROOT_PASSWORD="
    And the file "/opt/easyengine/sites/bh-site-type-php-12.test/.env" should contain "MYSQL_DATABASE=bh_site_type_php_12_test"
    And the file "/opt/easyengine/sites/bh-site-type-php-12.test/.env" should contain "MYSQL_USER=bh-site-type-php-12.test-"
    And the file "/opt/easyengine/sites/bh-site-type-php-12.test/.env" should contain "MYSQL_PASSWORD="
    And the database "bh_site_type_php_12_test" should exist in the db service of site "bh-site-type-php-12.test"
    And the php service of site "bh-site-type-php-12.test" should be able to connect to the site database
    And the "php" service of site "bh-site-type-php-12.test" should not be attached to the docker network "ee-global-backend-network"

  # DB-1 (inferred): the local db runs the same easyengine/mariadb image as global-db, so the same TLS demand applies.
  @known-bug
  Scenario: The mysql CLI inside the php container reaches the local db
    Given a php site "bh-site-type-php-12.test" created with "--with-db --local-db" exists
    Then Check local mysql database connection for 'bh-site-type-php-12.test'

  # Current behaviour: --local-db only acts inside --with-db, so alone it is silently ignored.
  Scenario: --local-db without --with-db creates no database at all
    When I run "bin/ee site create bh-site-type-php-13.test --type=php --local-db"
    Then the command should succeed
    And the site "bh-site-type-php-13.test" should have the field "app_sub_type" set to "php"
    And the site "bh-site-type-php-13.test" should have an empty "db_host"
    And the "db" service of site "bh-site-type-php-13.test" should not exist
    And the global database "bh_site_type_php_13_test" should not exist

  Scenario: A remote --dbhost with host:port and existing credentials is used as given
    Given the global database "bh_stp_remote14" with user "bh_stp_remote14" identified by "Bh-Stp-Remote-14" exists
    When I run "bin/ee site create bh-site-type-php-14.test --type=php --with-db --dbhost=global-db:3306 --dbname=bh_stp_remote14 --dbuser=bh_stp_remote14 --dbpass=Bh-Stp-Remote-14"
    Then the command should succeed
    And the site "bh-site-type-php-14.test" should have the field "db_host" set to "global-db"
    And the site "bh-site-type-php-14.test" should have the field "db_port" set to "3306"
    And the site "bh-site-type-php-14.test" should have the field "db_name" set to "bh_stp_remote14"
    And the site "bh-site-type-php-14.test" should have the field "db_user" set to "bh_stp_remote14"
    And the php service of site "bh-site-type-php-14.test" should be able to connect to the site database

  Scenario: A remote --dbhost needs --dbuser and --dbpass
    When I run "bin/ee site create bh-site-type-php-16.test --type=php --with-db --dbhost=bh-stp-unreachable.invalid"
    Then the return code should be 1
    And STDERR should contain "`--dbuser` and `--dbpass` are required for remote db host."
    And the site "bh-site-type-php-16.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-16.test" should not exist
    When I run "bin/ee site create bh-site-type-php-16.test --type=php --with-db --dbhost=bh-stp-unreachable.invalid --dbuser=bh_stp_u16"
    Then the return code should be 1
    And STDERR should contain "`--dbuser` and `--dbpass` are required for remote db host."

  # PHPT-5: nothing probes a remote db today, with or without --skip-check, so this only proves the create and the port
  # parsing; the skip itself becomes testable once PHPT-5 is fixed.
  Scenario: --skip-check creates the site without probing the remote db, and the port is parsed
    When I run "bin/ee site create bh-site-type-php-18.test --type=php --with-db --dbhost=bh-stp-unreachable.invalid:3307 --dbuser=bh_stp_u18 --dbpass=bh-stp-p18 --skip-check"
    Then the command should succeed
    And STDOUT should not contain "Verifying connection to remote database"
    And the site "bh-site-type-php-18.test" should have the field "db_host" set to "bh-stp-unreachable.invalid"
    And the site "bh-site-type-php-18.test" should have the field "db_port" set to "3307"
    And the site "bh-site-type-php-18.test" should have the field "db_user" set to "bh_stp_u18"
    And the site "bh-site-type-php-18.test" should have an empty "db_root_password"
    And the "db" service of site "bh-site-type-php-18.test" should not exist

  # PHPT-5: PHP::create_site() calls maybe_verify_remote_db_connection() only for --local-db, where it returns at once,
  # so remote credentials are never checked and --skip-check/--force are dead options (site-type-wp calls it for every create).
  @known-bug
  Scenario: An unreachable remote db host fails the create and rolls back
    When I run "bin/ee site create bh-site-type-php-17.test --type=php --with-db --dbhost=bh-stp-unreachable.invalid --dbuser=bh_stp_u17 --dbpass=bh-stp-p17"
    Then the command should fail
    And STDOUT should contain "Verifying connection to remote database"
    And the site "bh-site-type-php-17.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-17.test" should not exist

  # PHPT-1: another site's --dbname is refused up front; the rollback only drops what the create itself made.
  Scenario: Reusing another site's --dbname fails without dropping that database
    Given a php site "bh-site-type-php-20.test" created with "--with-db --dbname=bh_stp_shared20 --dbuser=bh_stp_user20 --dbpass=Bh-Stp-Pass-20" exists
    And the global database "bh_stp_shared20" contains the table "bh_marker"
    When I run "bin/ee site create bh-site-type-php-21.test --type=php --with-db --dbname=bh_stp_shared20"
    Then the command should fail
    And STDERR should contain "Database `bh_stp_shared20` is already used by site bh-site-type-php-20.test on the global database."
    And the directory "/opt/easyengine/sites/bh-site-type-php-21.test" should not exist
    And the site "bh-site-type-php-21.test" should not exist
    And the global database "bh_stp_shared20" should contain the table "bh_marker"
    And the php service of site "bh-site-type-php-20.test" should be able to connect to the site database

  # PHPT-3: another site's --dbuser is refused up front, so its user and password are untouched.
  Scenario: Reusing another site's --dbuser fails without dropping that user
    Given a php site "bh-site-type-php-23.test" created with "--with-db --dbname=bh_stp_db23 --dbuser=bh_stp_user23 --dbpass=Bh-Stp-Pass-23" exists
    When I run "bin/ee site create bh-site-type-php-24.test --type=php --with-db --dbuser=bh_stp_user23 --dbpass=Bh-Stp-Other-24"
    Then the command should fail
    And STDERR should contain "Database user `bh_stp_user23` is already used by site bh-site-type-php-23.test on the global database."
    And the global database "bh_site_type_php_24_test" should not exist
    And the site "bh-site-type-php-24.test" should not exist
    And the global database user "bh_stp_user23" should exist
    And the php service of site "bh-site-type-php-23.test" should be able to connect to the site database

  # PHPT-2: the default db name replaces "." and "-" with "_", so these two names collide. The pair also shares volume
  # names (PHPT-4), so the second create is refused before the database step.
  Scenario: Two site names that map to the same default db name do not destroy each other's database
    Given a php site "bh-site-type-php-22.x.test" created with "--with-db" exists
    And the global database "bh_site_type_php_22_x_test" contains the table "bh_marker"
    When I run "bin/ee site create bh-site-type-php-22-x.test --type=php --with-db"
    Then the command should fail
    And STDERR should contain "would share docker volumes (bhsitetypephp22xtest_*) with the existing site bh-site-type-php-22.x.test."
    And the site "bh-site-type-php-22-x.test" should not exist
    And the global database "bh_site_type_php_22_x_test" should contain the table "bh_marker"
    And the php service of site "bh-site-type-php-22.x.test" should be able to connect to the site database

  # PHPT-2: a default db name that is already taken (here by a database no site owns) gets a numbered suffix.
  Scenario: A taken default db name gets a numbered suffix and the existing database is kept
    Given the global database "bh_site_type_php_28_test" with user "bh_stp_user28" identified by "Bh-Stp-Pass-28" exists
    And the global database "bh_site_type_php_28_test" contains the table "bh_marker"
    When I run "bin/ee site create bh-site-type-php-28.test --type=php --with-db"
    Then the command should succeed
    And STDOUT should contain "Database `bh_site_type_php_28_test` already exists, using `bh_site_type_php_28_test_2` instead."
    And the site "bh-site-type-php-28.test" should have the field "db_name" set to "bh_site_type_php_28_test_2"
    And the global database "bh_site_type_php_28_test" should contain the table "bh_marker"
    And the php service of site "bh-site-type-php-28.test" should be able to connect to the site database
    When I run "bin/ee site delete bh-site-type-php-28.test --yes"
    Then the command should succeed
    And the global database "bh_site_type_php_28_test_2" should not exist
    And the global database "bh_site_type_php_28_test" should contain the table "bh_marker"

  Scenario: Deleting a global-db site drops its database and user
    Given a php site "bh-site-type-php-25.test" created with "--with-db --dbuser=bh_stp_user25 --dbpass=Bh-Stp-Pass-25" exists
    And the global database "bh_site_type_php_25_test" should exist
    When I run "bin/ee site delete bh-site-type-php-25.test --yes"
    Then the command should succeed
    And the site "bh-site-type-php-25.test" should not exist
    And the global database "bh_site_type_php_25_test" should not exist
    And the global database user "bh_stp_user25" should not exist

  Scenario: Deleting a local-db site removes the db container and its volumes
    Given a php site "bh-site-type-php-26.test" created with "--with-db --local-db" exists
    When I run "bin/ee site delete bh-site-type-php-26.test --yes"
    Then the command should succeed
    And the "db" service of site "bh-site-type-php-26.test" should not exist
    And the site "bh-site-type-php-26.test" should have 0 docker volumes
    And the directory "/opt/easyengine/sites/bh-site-type-php-26.test" should not exist
