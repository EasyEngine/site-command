Feature: Site Redirection

  Scenario: no_www-no_ssl redirection works properly
    When I run 'sudo bin/ee site create example.test'
    Then Request on 'localhost' with header 'Host: www.example.test' should contain following headers:
    | header                         |
    | HTTP/1.1 301 Moved Permanently |
    | Location: http://example.test/ |

  Scenario: www-no_ssl redirection works properly
    When I run 'sudo bin/ee site create www.example1.test'
    Then Request on 'localhost' with header 'Host: example1.test' should contain following headers:
    | header                              |
    | HTTP/1.1 301 Moved Permanently      |
    | Location: http://www.example1.test/ |

