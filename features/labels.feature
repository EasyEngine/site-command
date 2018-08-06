Feature: Container Labels


  Scenario: All easyengine containers are tagged
    Given I run "bin/ee site create labels.test"
    Then There should be 1 containers with labels
    """
    io.easyengine.site=labels.test
    """
