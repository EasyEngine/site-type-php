Feature: Container Labels

  # The old assertion was inverted and passed for any count but the expected one.
  Scenario: All easyengine containers, volumes and the network of a php site are tagged
    Given a php site "bh-site-type-php-90.test" exists
    Then There should be 3 containers with labels
    """
    io.easyengine.site=bh-site-type-php-90.test
    """
    And the site "bh-site-type-php-90.test" should have 8 docker volumes
    And the docker network "bh-site-type-php-90.test" should have the label "io.easyengine.site=bh-site-type-php-90.test"
    And the docker network "bh-site-type-php-90.test" should have the label "org.label-schema.vendor=EasyEngine"
