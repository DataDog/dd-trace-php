// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.hpp"
#include "spdlog/sinks/basic_file_sink.h"
#include "subscriber/waf.hpp"
#include <gtest/gtest.h>
#include <spdlog/spdlog.h>

std::string create_sample_rules_ok()
{
    const static char data[] = R"({
  "version": "2.1",
  "metadata": {
    "rules_version": "1.2.3"
  },
  "rules": [
    {
      "id": "blk-001-001",
      "name": "Block IP Addresses",
      "tags": {
        "type": "block_ip",
        "category": "security_response"
      },
      "conditions": [
        {
          "parameters": {
            "inputs": [
              {
                "address": "http.client_ip"
              }
            ],
            "list": [
              "192.168.1.1"
            ]
          },
          "operator": "ip_match"
        }
      ],
      "transformers": [],
      "on_match": [
        "block"
      ]
    },
    {
      "id": "blk-001-002",
      "name": "Block IP Addresses with all actions",
      "tags": {
        "type": "block_ip",
        "category": "security_response"
      },
      "conditions": [
        {
          "parameters": {
            "inputs": [
              {
                "address": "http.client_ip"
              }
            ],
            "list": [
              "192.168.1.2"
            ]
          },
          "operator": "ip_match"
        }
      ],
      "transformers": [],
      "on_match": [
        "block",
        "redirect",
        "stack_trace",
        "extract_schema"
      ]
    },
    {
        "id": "blk-001-003",
        "name": "Only stack trace",
        "tags": {
        "type": "block_ip",
        "category": "security_response"
        },
        "conditions": [
        {
          "parameters": {
            "inputs": [
              {
                "address": "http.client_ip"
              }
            ],
            "list": [
              "192.168.1.3"
            ]
          },
          "operator": "ip_match"
        }
        ],
        "transformers": [],
        "on_match": [
            "stack_trace"
        ]
    },
    {
      "id": "crs-913-110",
      "name": "Found request header associated with Acunetix security scanner",
      "tags": {
        "type": "security_scanner",
        "crs_id": "913110",
        "category": "attack_attempt"
      },
      "conditions": [
        {
          "parameters": {
            "inputs": [
              {
                "address": "server.request.headers.no_cookies"
              }
            ],
            "list": [
              "acunetix-product"
            ]
          },
          "operator": "phrase_match"
        }
      ],
      "transformers": [
        "lowercase"
      ]
    },
    {
      "id": "req_shutdown_rule",
      "name": "Rule match on response code",
      "tags": {
        "type": "req_shutdown_type",
        "crs_id": "none",
        "category": "attack_attempt"
      },
      "conditions": [
        {
          "parameters": {
            "inputs": [
              {
                "address": "server.request.headers.no_cookies"
              }
            ],
            "list": [
              "Arachni"
            ]
          },
          "operator": "phrase_match"
        },
        {
          "parameters": {
            "inputs": [
              {
                "address": "server.response.code"
              }
            ],
            "regex": 1991,
            "options": {
              "case_sensitive": "false"
            }
          },
          "operator": "match_regex"
        }
      ]
    }
  ],
  "processors": [
    {
      "id": "processor-001",
      "generator": "extract_schema",
      "conditions": [
        {
          "operator": "equals",
          "parameters": {
            "inputs": [
              {
                "address": "waf.context.processor",
                "key_path": [
                  "extract-schema"
                ]
              }
            ],
            "type": "boolean",
            "value": true
          }
        }
      ],
      "parameters": {
        "mappings": [
          {
            "inputs": [
              {
                "address": "server.request.headers.no_cookies"
              }
            ],
            "output": "_dd.appsec.s.req.headers.no_cookies"
          },
          {
            "inputs": [
              {
                "address": "server.request.body"
              }
            ],
            "output": "_dd.appsec.s.req.body"
          }
        ],
        "scanners": [
          {
            "tags": {
              "category": "pii"
            }
          }
        ]
      },
      "evaluate": false,
      "output": true
    },
    {
      "id": "http-endpoint-fingerprint",
      "generator": "http_endpoint_fingerprint",
      "conditions": [
        {
          "operator": "exists",
          "parameters": {
            "inputs": [
              {
                "address": "waf.context.event"
              },
              {
                "address": "server.business_logic.users.login.failure"
              },
              {
                "address": "server.business_logic.users.login.success"
              }
            ]
          }
        }
      ],
      "parameters": {
        "mappings": [
          {
            "method": [
              {
                "address": "server.request.method"
              }
            ],
            "uri_raw": [
              {
                "address": "server.request.uri.raw"
              }
            ],
            "body": [
              {
                "address": "server.request.body"
              }
            ],
            "query": [
              {
                "address": "server.request.query"
              }
            ],
            "output": "_dd.appsec.fp.http.endpoint"
          }
        ]
      },
      "evaluate": false,
      "output": true
    },
    {
      "id": "http-header-fingerprint",
      "generator": "http_header_fingerprint",
      "conditions": [
        {
          "operator": "exists",
          "parameters": {
            "inputs": [
              {
                "address": "waf.context.event"
              },
              {
                "address": "server.business_logic.users.login.failure"
              },
              {
                "address": "server.business_logic.users.login.success"
              }
            ]
          }
        }
      ],
      "parameters": {
        "mappings": [
          {
            "headers": [
              {
                "address": "server.request.headers.no_cookies"
              }
            ],
            "output": "_dd.appsec.fp.http.header"
          }
        ]
      },
      "evaluate": false,
      "output": true
    },
    {
      "id": "http-network-fingerprint",
      "generator": "http_network_fingerprint",
      "conditions": [
        {
          "operator": "exists",
          "parameters": {
            "inputs": [
              {
                "address": "waf.context.event"
              },
              {
                "address": "server.business_logic.users.login.failure"
              },
              {
                "address": "server.business_logic.users.login.success"
              }
            ]
          }
        }
      ],
      "parameters": {
        "mappings": [
          {
            "headers": [
              {
                "address": "server.request.headers.no_cookies"
              }
            ],
            "output": "_dd.appsec.fp.http.network"
          }
        ]
      },
      "evaluate": false,
      "output": true
    },
    {
      "id": "session-fingerprint",
      "generator": "session_fingerprint",
      "conditions": [
        {
          "operator": "exists",
          "parameters": {
            "inputs": [
              {
                "address": "waf.context.event"
              },
              {
                "address": "server.business_logic.users.login.failure"
              },
              {
                "address": "server.business_logic.users.login.success"
              }
            ]
          }
        }
      ],
      "parameters": {
        "mappings": [
          {
            "cookies": [
              {
                "address": "server.request.cookies"
              }
            ],
            "session_id": [
              {
                "address": "usr.session_id"
              }
            ],
            "user_id": [
              {
                "address": "usr.id"
              }
            ],
            "output": "_dd.appsec.fp.session"
          }
        ]
      },
      "evaluate": false,
      "output": true
    }
  ],
  "scanners": [],
  "actions": [
    {
      "id": "redirect",
      "type": "redirect_request",
      "parameters": {
        "location": "https://localhost"
      }
    }
  ]
})";

    char tmpl[] = "/tmp/test_ddappsec_XXXXXX";
    int fd = mkstemp(tmpl);
    std::FILE *tmpf = fdopen(fd, "wb+");
    std::fwrite(data, sizeof(data) - 1, 1, tmpf);
    std::fclose(tmpf);

    return tmpl;
}

std::string create_sample_rules_invalid()
{
    const static char data[] =
        R"({"version":"2.1","metadata":{"rules_version":"1.2.3"},"rules":[{"id":1,"name":"rule1","tags":{"category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1"}],"regex":".*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":["x"]}],"regex":".*"}}]},{"id":2,"name":"rule2","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"squash","parameters":{"inputs":[{"address":"arg1"}],"regex":".*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2"}],"regex":".*"}}]},{"id":3,"name":"rule3","tags":{"category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1"}],"regex":".*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":["y"]}],"regex":".*"}}]},{"id":4,"name":"rule4","tags":{"type":"flow1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1"}],"regex":".*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2","key_path":["x"]}],"regex":".*"}},{"operator":"match_regex","parameters":{"regex":".*"}}]},{"id":5,"name":"rule5","tags":{"type":"type1","category":"category1"},"conditions":[{"operator":"match_regex","parameters":{"inputs":[{"address":"arg1"}],"regex":".*"}},{"operator":"match_regex","parameters":{"inputs":[{"address":"arg2"}],"regex":".*"}}]}]})";

    char tmpl[] = "/tmp/test_ddappsec_XXXXXX";
    int fd = mkstemp(tmpl);
    std::FILE *tmpf = fdopen(fd, "wb+");
    std::fwrite(data, sizeof(data) - 1, 1, tmpf);
    std::fclose(tmpf);

    return tmpl;
}

int main(int argc, char **argv)
{
    auto logger = spdlog::basic_logger_mt("ddappsec", "/tmp/helper-test.log");
    spdlog::set_default_logger(logger);
    spdlog::set_level(spdlog::level::debug);
    dds::waf::initialise_logging(spdlog::level::debug);

    ::testing::InitGoogleTest(&argc, argv);
    return RUN_ALL_TESTS();
}
