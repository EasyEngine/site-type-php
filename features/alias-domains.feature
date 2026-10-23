Feature: Alias domains of php sites (create --alias-domains, update --add/--delete-alias-domains)

  Scenario: Create a php site with plain and wildcard alias domains
    When I run "bin/ee site create bh-site-type-php-70.test --type=php --alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'"
    Then the command should succeed
    And STDOUT should contain "| Alias Domains | bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test"
    And the site "bh-site-type-php-70.test" should have the field "alias_domains" set to "bh-site-type-php-70.test,bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test"

  Scenario: Alias domains reach nginx-proxy and the site nginx
    Given a php site "bh-site-type-php-70.test" created with "--alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'" exists
    Then the file "/opt/easyengine/sites/bh-site-type-php-70.test/docker-compose.yml" should contain "VIRTUAL_HOST=${VIRTUAL_HOST},bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-70.test/docker-compose.yml" should contain "CERT_NAME=${VIRTUAL_HOST}"
    And the file "/opt/easyengine/sites/bh-site-type-php-70.test/config/nginx/conf.d/main.conf" should contain "server_name bh-site-type-php-70.test bh-site-type-php-70-a.test bh-site-type-php-70-b.test *.bh-site-type-php-70.test;"
    And the hosts file should have an entry for "bh-site-type-php-70.test"
    And the hosts file should not have an entry for "bh-site-type-php-70-a.test"

  Scenario: Every alias domain serves the site
    Given a php site "bh-site-type-php-70.test" created with "--alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'" exists
    Then a GET request to "http://bh-site-type-php-70-a.test/" via "127.0.0.1" should return 200
    And the response should contain "Congratulations!"
    And a GET request to "http://bh-site-type-php-70-b.test/" via "127.0.0.1" should return 200
    And a GET request to "http://any.bh-site-type-php-70.test/" via "127.0.0.1" should return 200
    And a GET request to "http://bh-site-type-php-70-z.test/" via "127.0.0.1" should not return 200

  Scenario: An alias domain that already belongs to another site is refused
    Given a php site "bh-site-type-php-70.test" created with "--alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'" exists
    When I run "bin/ee site create bh-site-type-php-71.test --type=php --alias-domains=bh-site-type-php-70-a.test"
    Then the return code should be 1
    And STDERR should contain "Site bh-site-type-php-70-a.test already exists as an alias domain for site: bh-site-type-php-70.test."
    And the site "bh-site-type-php-71.test" should not exist
    And the directory "/opt/easyengine/sites/bh-site-type-php-71.test" should not exist

  Scenario: A site can not be created with a name that is another site's alias
    Given a php site "bh-site-type-php-70.test" created with "--alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'" exists
    When I run "bin/ee site create bh-site-type-php-70-b.test --type=php"
    Then the return code should be 1
    And STDERR should contain "Site bh-site-type-php-70-b.test already exists as an alias domain for site: bh-site-type-php-70.test. Please delete it from alias domains of bh-site-type-php-70.test if you want to create an independent site for it."
    And the site "bh-site-type-php-70-b.test" should not exist

  Scenario: Spaces around alias domains are trimmed
    When I run "bin/ee site create bh-site-type-php-78.test --type=php --alias-domains=' bh-site-type-php-78-a.test , bh-site-type-php-78-b.test '"
    Then the command should succeed
    And the site "bh-site-type-php-78.test" should have the field "alias_domains" set to "bh-site-type-php-78.test,bh-site-type-php-78-a.test,bh-site-type-php-78-b.test"
    And a GET request to "http://bh-site-type-php-78-b.test/" via "127.0.0.1" should return 200

  # Regression check for blank entries (fixed by EasyEngine/site-type-php#114). A regression renders an invalid nginx-proxy config (`upstream  {`) that breaks proxy reloads for every site for ~2 minutes: hence @destructive.
  @destructive
  Scenario: Empty entries in --alias-domains are dropped
    When I run "bin/ee site create bh-site-type-php-75.test --type=php --alias-domains='bh-site-type-php-75-a.test,,bh-site-type-php-75-b.test,'"
    Then the command should succeed
    And the site "bh-site-type-php-75.test" should have the field "alias_domains" set to "bh-site-type-php-75.test,bh-site-type-php-75-a.test,bh-site-type-php-75-b.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-75.test/docker-compose.yml" should contain "VIRTUAL_HOST=${VIRTUAL_HOST},bh-site-type-php-75-a.test,bh-site-type-php-75-b.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-75.test/docker-compose.yml" should not contain ",,"

  # Regression check: an alias like _wildcard.<site> could overwrite proxy auth files (fixed by EasyEngine/site-type-php#114).
  Scenario Outline: An alias domain that is not a valid hostname is refused
    When I run "bin/ee site create <site> --type=php --alias-domains='<alias>'"
    Then the return code should be 1
    And STDERR should contain "Invalid alias domain(s)"
    And the site "<site>" should not exist

    Examples:
      | site                       | alias                                |
      | bh-site-type-php-76.test   | _wildcard.bh-site-type-php-76.test   |
      | bh-site-type-php-76-1.test | -bh-site-type-php-76-a.test          |

    # A space in VIRTUAL_HOST breaks the nginx-proxy config the same way as the blank entries above.
    @destructive
    Examples:
      | site                       | alias                                |
      | bh-site-type-php-76-2.test | bh-site-type-php-76 a.test           |

  Scenario: Add an alias domain to a php site
    Given a php site "bh-site-type-php-77.test" exists
    When I run "bin/ee site update bh-site-type-php-77.test --add-alias-domains=bh-site-type-php-77-a.test"
    Then the command should succeed
    And STDOUT should contain "Success: Alias domains updated on site bh-site-type-php-77.test."
    And the site "bh-site-type-php-77.test" should have the field "alias_domains" set to "bh-site-type-php-77.test,bh-site-type-php-77-a.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-77.test/docker-compose.yml" should contain "VIRTUAL_HOST=${VIRTUAL_HOST},bh-site-type-php-77-a.test"
    And I wait until a GET request to "http://bh-site-type-php-77-a.test/" via "127.0.0.1" returns 200 within 60 seconds
    And a GET request to "http://bh-site-type-php-77.test/" should return 200

  Scenario: Adding an alias domain the site already has is refused
    Given a php site "bh-site-type-php-77.test" exists
    And the site "bh-site-type-php-77.test" has the alias domain "bh-site-type-php-77-a.test"
    When I run "bin/ee site update bh-site-type-php-77.test --add-alias-domains=bh-site-type-php-77-a.test"
    Then the return code should be 1
    And STDERR should contain "Error: Alias domains: bh-site-type-php-77-a.test is/are already present on the site."

  Scenario: Adding a mix of new and existing alias domains skips the existing ones
    Given a php site "bh-site-type-php-77.test" exists
    And the site "bh-site-type-php-77.test" has the alias domain "bh-site-type-php-77-a.test"
    When I run "bin/ee site update bh-site-type-php-77.test --add-alias-domains=bh-site-type-php-77-a.test,bh-site-type-php-77-b.test"
    Then the command should succeed
    And STDERR should contain "Following domains: bh-site-type-php-77-a.test is/are already present on site, skipping addition of those."
    And the site "bh-site-type-php-77.test" should have the field "alias_domains" set to "bh-site-type-php-77.test,bh-site-type-php-77-a.test,bh-site-type-php-77-b.test"

  Scenario: Delete an alias domain from a php site
    Given a php site "bh-site-type-php-77.test" exists
    And the site "bh-site-type-php-77.test" has the alias domain "bh-site-type-php-77-c.test"
    When I run "bin/ee site update bh-site-type-php-77.test --delete-alias-domains=bh-site-type-php-77-c.test"
    Then the command should succeed
    And STDOUT should contain "Success: Alias domains updated on site bh-site-type-php-77.test."
    And the site "bh-site-type-php-77.test" should have the field "alias_domains" not containing "bh-site-type-php-77-c.test"
    And the file "/opt/easyengine/sites/bh-site-type-php-77.test/docker-compose.yml" should not contain "bh-site-type-php-77-c.test"
    And I wait until a GET request to "http://bh-site-type-php-77-c.test/" via "127.0.0.1" no longer returns 200 within 60 seconds

  Scenario: The primary domain can not be deleted from the alias domains
    Given a php site "bh-site-type-php-77.test" exists
    When I run "bin/ee site update bh-site-type-php-77.test --delete-alias-domains=bh-site-type-php-77.test"
    Then the return code should be 1
    And STDERR should contain "Primary site domain: `bh-site-type-php-77.test` can not be deleted."

  Scenario: Deleting an alias domain the site does not have is refused
    Given a php site "bh-site-type-php-77.test" exists
    When I run "bin/ee site update bh-site-type-php-77.test --delete-alias-domains=bh-site-type-php-77-nope.test"
    Then the return code should be 1
    And STDERR should contain "Domains to delete is/are not a subset of already existing alias domains."

  Scenario: An existing site can not be added as an alias domain
    Given a php site "bh-site-type-php-77.test" exists
    And a php site "bh-site-type-php-74.test" exists
    When I run "bin/ee site update bh-site-type-php-77.test --add-alias-domains=bh-site-type-php-74.test"
    Then the return code should be 1
    And STDERR should contain "bh-site-type-php-74.test already exists as a site."

  Scenario: Another site's alias domain can not be added
    Given a php site "bh-site-type-php-77.test" exists
    And a php site "bh-site-type-php-70.test" created with "--alias-domains='bh-site-type-php-70-a.test,bh-site-type-php-70-b.test,*.bh-site-type-php-70.test'" exists
    When I run "bin/ee site update bh-site-type-php-77.test --add-alias-domains=bh-site-type-php-70-a.test"
    Then the return code should be 1
    And STDERR should contain "Site bh-site-type-php-70-a.test already exists as an alias domain for site: bh-site-type-php-70.test."

  Scenario: A wildcard alias domain can be added later
    Given a php site "bh-site-type-php-79.test" exists
    When I run "bin/ee site update bh-site-type-php-79.test --add-alias-domains='*.bh-site-type-php-79.test'"
    Then the command should succeed
    And I wait until a GET request to "http://sub.bh-site-type-php-79.test/" via "127.0.0.1" returns 200 within 60 seconds
