Feature: Container Labels
  Site containers, volumes and networks carry the EasyEngine labels other commands filter on.

  Scenario: All easyengine containers are tagged
    Given the html site "bh-site-command-130.test" exists
    Then There should be 1 containers with labels
    """
    io.easyengine.site=bh-site-command-130.test
    """
    And There should be 1 containers with labels
    """
    io.easyengine.site=bh-site-command-130.test
    com.docker.compose.service=nginx
    """

  Scenario: Site volumes and network are tagged
    Given the html site "bh-site-command-130.test" exists
    Then the site "bh-site-command-130.test" should have 3 docker volumes
    And the docker volume "bhsitecommand130test_htdocs" should exist
    And the docker volume "bhsitecommand130test_config_nginx" should exist
    And the docker volume "bhsitecommand130test_log_nginx" should exist
    When I run 'docker network inspect -f "{{json .Labels}}" bh-site-command-130.test'
    Then the command should succeed
    And STDOUT should contain ""io.easyengine.site":"bh-site-command-130.test""
    And STDOUT should contain ""org.label-schema.vendor":"EasyEngine""
