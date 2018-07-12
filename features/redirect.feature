Feature: Site Redirection

  Scenario: no_www-no_ssl redirection works properly
    When I run 'bin/ee site create example.test'
    Then Request on 'localhost' with header 'Host: www.example.test' should contain following headers:
    | header                         |
    | HTTP/1.1 301 Moved Permanently |
    | Location: http://example.test/ |

  Scenario: www-no_ssl redirection works properly
    When I run 'bin/ee site create www.example1.test'
    Then Request on 'localhost' with header 'Host: example1.test' should contain following headers:
    | header                              |
    | HTTP/1.1 301 Moved Permanently      |
    | Location: http://www.example1.test/ |

  Scenario: no_www-ssl redirection works properly
    When I run 'bin/ee site create example2.test --le --le-mail=test@test.com --skip-status-check'
    And Site 'example2.test' has certs
    Then Request on 'localhost' with header 'Host: www.example2.test' should contain following headers:
    | header                           |
    | HTTP/1.1 301 Moved Permanently   |
    | Location: https://example2.test/ |
    And Request on 'localhost:443' with header 'Host: www.example2.test' should contain following headers:
    | header                           |
    | HTTP/1.1 301 Moved Permanently   |
    | Location: https://example2.test/ |

  Scenario: www-ssl redirection works properly
    When I run 'bin/ee site create www.example3.test'
    And Site 'www.example3.test' has certs
    Then Request on 'localhost' with header 'Host: www.example3.test' should contain following headers:
    | header                               |
    | HTTP/1.1 301 Moved Permanently       |
    | Location: https://www.example3.test/ |
    And Request on 'localhost:443' with header 'Host: example3.test' should contain following headers:
    | header                               |
    | HTTP/1.1 301 Moved Permanently       |
    | Location: https://www.example3.test/ |
