Feature: Site Redirection

  Scenario: no_www-no_ssl redirection works properly
    When I run '/bin/bash -c 'echo "127.0.0.1 www.example.test" >> /etc/hosts''
    And I run 'bin/ee site create example.test'
    Then After delay of 5 seconds
    Then Request on 'localhost' with header 'Host: www.example.test' should contain following headers:
    | header                         |
    | HTTP/1.1 301 Moved Permanently |
    | Location: http://example.test/ |

  Scenario: www-no_ssl redirection works properly
    When I run '/bin/bash -c 'echo "127.0.0.1 example1.test" >> /etc/hosts''
    And I run 'bin/ee site create www.example1.test'
    Then After delay of 5 seconds
    Then Request on 'localhost' with header 'Host: example1.test' should contain following headers:
    | header                              |
    | HTTP/1.1 301 Moved Permanently      |
    | Location: http://www.example1.test/ |
