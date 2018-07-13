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

  Scenario: no_www-ssl redirection works properly
    When I run 'sudo bin/ee site create example2.test --le --le-mail=test@test.com --skip-status-check'
    Then Request on 'localhost' with header 'Host: www.example2.test' should contain following headers:
    | header                           |
    | HTTP/1.1 301 Moved Permanently   |
    | Location: https://example2.test/ |
    And Request on 'https://www.example2.test' with resolve option 'www.example2.test:443:127.0.0.1' should contain following headers:
    | header                           |
    | HTTP/1.1 301 Moved Permanently   |
    | Location: https://example2.test/ |

  Scenario: www-ssl redirection works properly
    When I run 'sudo bin/ee site create www.example3.test --le --le-mail=test@test.com --skip-status-check'
    Then Request on 'localhost' with header 'Host: example3.test' should contain following headers:
    | header                               |
    | HTTP/1.1 301 Moved Permanently       |
    | Location: https://www.example3.test/ |
    And Request on 'https://example3.test/' with resolve option 'example3.test:443:127.0.0.1' should contain following headers:
    | header                               |
    | HTTP/1.1 301 Moved Permanently       |
    | Location: https://www.example3.test/ |
