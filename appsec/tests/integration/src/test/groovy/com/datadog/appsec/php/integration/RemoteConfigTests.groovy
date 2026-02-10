package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_agent.rem_cfg.Capability
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Span
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import static com.datadog.appsec.php.test.JsonMatcher.matchesJson

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@EnabledIf('isEnabled')
class RemoteConfigTests {
    static boolean enabled = !variant.contains('zts') && phpVersion == '8.3'

    private static final Target INITIAL_TARGET = new Target('some-name', 'none', '')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'nginx-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    @BeforeAll
    static void beforeAll() {
        ExecResult res = CONTAINER.execInContainer(
                'bash', '-c',
                '''sed -e '/appsec.enabled/d' -e '/appsec.rules=/d' /etc/php/php.ini > /etc/php/php-rc.ini;
                   kill -9 `pgrep php-fpm`;
                   export  DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=1 DD_ENV=;
                   php-fpm -y /etc/php-fpm.conf -c /etc/php/php-rc.ini''')
        assert res.exitCode == 0
    }

    @Test
    void 'test remote activation and capabilities'() {
        def doReq = { int expectedStatus ->
            HttpRequest req = CONTAINER.buildReq('/hello.php')
                    .GET()
                    .header('User-agent', 'dd-test-scanner-log-block')
                    .build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        doReq.call(200)

        RemoteConfigRequest rcr = applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        def capSet = Capability.forByteArray(rcr.client.capabilities)

        [
                Capability.ASM_ACTIVATION,
                Capability.ASM_IP_BLOCKING,
                Capability.ASM_DD_RULES,
                Capability.ASM_EXCLUSIONS,
                Capability.ASM_REQUEST_BLOCKING,
                Capability.ASM_RESPONSE_BLOCKING,
                Capability.ASM_USER_BLOCKING,
                Capability.ASM_CUSTOM_RULES,
                Capability.ASM_CUSTOM_BLOCKING_RESPONSE,
                Capability.ASM_TRUSTED_IPS,
                Capability.ASM_RASP_LFI,
                Capability.ASM_RASP_SSRF,
                Capability.ASM_RASP_SQLI,
                Capability.ASM_DD_MULTICONFIG,
                Capability.ASM_TRACE_TAGGING_RULES,
                Capability.ASM_ENDPOINT_FINGERPRINT,
                Capability.ASM_SESSION_FINGERPRINT,
                Capability.ASM_NETWORK_FINGERPRINT,
                Capability.ASM_HEADER_FINGERPRINT,
                Capability.ASM_PROCESSOR_OVERRIDES,
                Capability.ASM_CUSTOM_DATA_SCANNERS,
        ].each { assert it in capSet }

        doReq.call(403)

        dropRemoteConfig(INITIAL_TARGET)

        doReq.call(200)
    }

    @Test
    void 'test asm_data'() {
        def doReq = { String ip, int expectedStatus ->
            HttpRequest req = CONTAINER.buildReq('/hello.php')
                    .GET()
                    .header('X-Real-Ip', ip)
                    .build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        doReq.call('1.2.3.4', 200)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM_DATA/mydata/config': [
                        rules_data: [
                                [
                                        id: 'blocked_ips',
                                        type: 'ip_with_expiration',
                                        data: [
                                                [
                                                        expiration: 0,
                                                        value: '1.2.3.0/24'
                                                ]
                                        ]
                                ]
                        ]

                ]
        ])

        doReq.call('1.2.3.4', 403)
        doReq.call('1.2.4.1', 200)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]]])

        doReq.call('1.2.3.4', 200)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom query deeply nested param matches and reports full key path'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_query_deep/config': [
                        custom_rules: [[
                                               id: 'query_deep_rule',
                                               name: 'query_deep_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.query',
                                                                                             key_path: ['a','b','c','d']
                                                                                     ]],
                                                                            regex: 'poison'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        def req = CONTAINER.buildReq('/hello.php?a[b][c][d]=poison').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "query_deep_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.query",
                             "key_path" : ["a","b","c","d"],
                             "value" : "poison",
                             "highlight" : ["poison"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom query array of objects matches and reports key path with index'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_query_array_obj/config': [
                        custom_rules: [[
                                               id: 'query_array_obj_rule',
                                               name: 'query_array_obj_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.query',
                                                                                             key_path: ['items','0','name']
                                                                                     ]],
                                                                            regex: '^bad$'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        def req = CONTAINER.buildReq('/hello.php?items[0][name]=bad&items[1][name]=ok').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "query_array_obj_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.query",
                             "key_path" : ["items","0","name"],
                             "value" : "bad",
                             "highlight" : ["bad"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom cookie rule matches and reports parameter path'() {
        // Enable appsec with a custom cookie rule
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_cookie_rule/config': [
                        custom_rules: [[
                                               id: 'cookie_rule',
                                               name: 'cookie_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.cookies',
                                                                                             key_path: ['session']
                                                                                     ]],
                                                                            regex: '(?i)bad'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        // first, good cookie -> 200
        def req = CONTAINER.buildReq('/hello.php').header('Cookie', 'session=good').GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // bad cookie -> 403 and json shows correct address/key_path/value/highlight
        req = CONTAINER.buildReq('/hello.php').header('Cookie', 'session=bad').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "cookie_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.cookies",
                             "key_path" : ["session"],
                             "value" : "bad",
                             "highlight" : ["bad"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom header rule matches and reports parameter path'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_header_rule/config': [
                        custom_rules: [[
                                               id: 'header_rule',
                                               name: 'header_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.headers.no_cookies',
                                                                                             key_path: ['x-demo-header']
                                                                                     ]],
                                                                            regex: '^foo$'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        // no match
        def req = CONTAINER.buildReq('/hello.php')
                .header('X-Demo-Header', 'bar')
                .GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // match + verify json payload
        req = CONTAINER.buildReq('/hello.php')
                .header('X-Demo-Header', 'foo')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "header_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.headers.no_cookies",
                             "key_path" : ["x-demo-header"],
                             "value" : "foo",
                             "highlight" : ["foo"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'header rule is case-insensitive on header name'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_header_rule_ci/config': [
                        custom_rules: [[
                                               id: 'header_rule_ci',
                                               name: 'header_rule_ci',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.headers.no_cookies',
                                                                                             key_path: ['x-demo-ci']
                                                                                     ]],
                                                                            regex: '^foo$'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        // supply uppercase header name; should still match
        def req = CONTAINER.buildReq('/hello.php')
                .header('X-DEMO-CI', 'foo')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "header_rule_ci" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.headers.no_cookies",
                             "key_path" : ["x-demo-ci"],
                             "value" : "foo",
                             "highlight" : ["foo"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'cookie rule is case-sensitive on cookie name'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_cookie_case_rule/config': [
                        custom_rules: [[
                                               id: 'cookie_rule_cs',
                                               name: 'cookie_rule_cs',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.cookies',
                                                                                             key_path: ['session']
                                                                                     ]],
                                                                            regex: '(?i)bad'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        // Using cookie name 'Session' should not match rule expecting 'session'
        def req = CONTAINER.buildReq('/hello.php').header('Cookie', 'Session=bad').GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom query nested param matches and reports key path'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_query_nested/config': [
                        custom_rules: [[
                                               id: 'query_nested_rule',
                                               name: 'query_nested_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.query',
                                                                                             key_path: ['nested', 'deep']
                                                                                     ]],
                                                                            regex: 'poison'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        def req = CONTAINER.buildReq('/hello.php?nested[deep]=poison').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "query_nested_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.query",
                             "key_path" : ["nested","deep"],
                             "value" : "poison",
                             "highlight" : ["poison"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'custom query array index matches and reports key path'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_query_array/config': [
                        custom_rules: [[
                                               id: 'query_array_rule',
                                               name: 'query_array_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.query',
                                                                                             key_path: ['arr', '0']
                                                                                     ]],
                                                                            regex: '^bad0$'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['block']
                                       ]]
                ],
        ])

        def req = CONTAINER.buildReq('/hello.php?arr[0]=bad0&arr[1]=ok').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 403
        }
        def appsecJson = trace.first().meta."_dd.appsec.json"
        def expJson = '''{
           "triggers" : [
              {
                 "rule" : { "id" : "query_array_rule" },
                 "rule_matches" : [
                    {
                       "parameters" : [
                          {
                             "address" : "server.request.query",
                             "key_path" : ["arr","0"],
                             "value" : "bad0",
                             "highlight" : ["bad0"]
                          }
                       ]
                    }
                 ]
              }
           ]
        }'''
        assertThat appsecJson, matchesJson(expJson, false, true)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'invalid custom action type falls back to record (no block)'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_bad_action/config': [
                        custom_rules: [[
                                               id: 'bad_action_rule',
                                               name: 'bad_action_rule',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[ address: 'server.request.query' ]],
                                                                            regex: 'trigger_bad_action'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               on_match: ['bad_action']
                                       ]],
                        actions: [[
                                          id: 'bad_action',
                                          type: 'non_existing_action_type',
                                          parameters: [:]
                                  ]]
                ],
        ])

        def req = CONTAINER.buildReq('/hello.php?q=trigger_bad_action').GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
            // should not block due to invalid action type
            assert resp.statusCode() == 200
        }

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'rules override redirect falls back to record when actions cleared'() {
        def doReq = { Integer expectedStatus, Map headers = [:] ->
            def br = CONTAINER.buildReq('/hello.php').GET()
            headers.each { k, v -> br.header(k, v) }
            HttpRequest req = br.build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        // enable appsec
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
        ])

        // set override to redirect for builtin UA rule and define redirect action
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_override_redirect/config': [
                        rules_override: [[
                                                 rules_target: [[ rule_id: 'ua0-600-56x' ]],
                                                 on_match: ['redirect']
                                         ]],
                        actions: [[
                                          id: 'redirect',
                                          type: 'redirect_request',
                                          parameters: [
                                                  status_code: '303',
                                                  location: 'https://datadoghq.com'
                                          ]
                                  ]]
                ]
        ])
        // expect redirect
        doReq.call(303, ['User-agent': 'dd-test-scanner-log-block'])

        // now clear actions while keeping the override on_match to 'redirect'
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_override_redirect/config': [
                        rules_override: [[
                                                 rules_target: [[ rule_id: 'ua0-600-56x' ]],
                                                 on_match: ['redirect']
                                         ]],
                        actions: []
                ]
        ])
        // expect no blocking (record), hence 200
        doReq.call(200, ['User-agent': 'dd-test-scanner-log-block'])

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test asm_dd'() {
        def doReq = { int expectedStatus ->
            HttpRequest req = CONTAINER.buildReq('/hello.php?a=matched+value').GET().build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        doReq.call(200)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'employee/ASM_DD/full_cfg/config':
                        [
                                version: '2.1',
                                rules: [[
                                                id: 'partial_match_values',
                                                name: 'Partially match values',
                                                tags: [
                                                        type: 'security_scanner',
                                                        category: 'attack_attempt'
                                                ],
                                                conditions: [[
                                                                     parameters: [
                                                                             inputs: [[ address: 'server.request.query' ]],
                                                                             regex: '.*matched.+value.*'
                                                                     ],
                                                                     operator: 'match_regex'
                                                             ]],
                                                transformers: ['values_only'],
                                                on_match: ['block']
                                        ]]
                        ]
        ])

        doReq.call(403)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]]])

        doReq.call(200)

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test asm'() {
        def doReq = { String path, int expectedStatus, Map headers = [:] ->
            def br = CONTAINER.buildReq(path).GET()
            headers.each { k, v -> br.header(k, v) }
            HttpRequest req = br.build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        doReq.call('/hello.php', 200)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_user_cfg_2/config': [
                        actions: [[
                                          id: 'block_custom',
                                          type: 'block_request',
                                          parameters: [
                                                  status_code: '408'
                                          ]
                                  ]]
                ],
                'datadog/2/ASM/custom_user_cfg_1/config': [
                        custom_rules: [[
                                               id: 'partial_match_values',
                                               name: 'Partially match values',
                                               tags: [
                                                       type: 'security_scanner',
                                                       category: 'attack_attempt'
                                               ],
                                               conditions: [[
                                                                    parameters: [
                                                                            inputs: [[
                                                                                             address: 'server.request.query'
                                                                                     ]],
                                                                            regex: '.*matched.+value.*'
                                                                    ],
                                                                    operator: 'match_regex'
                                                            ]],
                                               transformers: ['values_only'],
                                               on_match: ['block_custom']

                                       ]],
                        exclusions: [[
                                             id: 'excl1',
                                             rules_target: [[
                                                                    rule_id: 'ua0-600-56x'
                                                            ]],
                                             conditions: [[
                                                                  operator: 'match_regex',
                                                                  parameters: [
                                                                          inputs: [[
                                                                                           address: 'server.request.query'
                                                                                   ]],
                                                                          regex: 'excluded'
                                                                  ]
                                                          ]]
                                     ]],
                        rules_override: [[
                                                 rules_target: [[
                                                                        rule_id: 'ua0-600-56x'
                                                                ]],
                                                 on_match: ['block_custom2'],
                                                 enabled: true
                                         ]],
                        actions: [
                                [
                                        id: 'block_custom2',
                                        type: 'block_request',
                                        parameters: [
                                                status_code: '410'
                                        ]
                                ]
                        ]
                ]
        ])

        // matches user rule 'partial_match_values'
        doReq.call('/hello.php?a=matched+value1', 408)

        // matches exclusion rule 'excl1'
        doReq.call('/hello.php?b=excluded', 200, ['User-agent': 'dd-test-scanner-log-block'])

        // action is overridden in rules_override to block_custom2 (code: 410)
        doReq.call('/hello.php', 410, ['User-agent': 'dd-test-scanner-log-block'])

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': null /* keep */,
                'datadog/2/ASM/custom_user_cfg_1/config': null /* keep */,
                'datadog/2/ASM/custom_user_cfg_2/config': [
                        actions: [[
                                          id: 'block_custom',
                                          type: 'block_request',
                                          parameters: [
                                                  status_code: '409'
                                          ]
                                  ]]
                ],
        ])

        // status code changed to 409
        doReq.call('/hello.php?a=matched+value1', 409)

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]]])

        doReq.call('/hello.php?a=matched+value1', 200)
        doReq.call('/hello.php?b=excluded', 403, ['User-agent': 'dd-test-scanner-log-block'])
        doReq.call('/hello.php', 403, ['User-agent': 'dd-test-scanner-log-block'])

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test env change'() {
        Target newTarget = new Target('some-name', 'another-env', '')

        def doReq = { Integer expectedStatus, String path, Map headers = [:] ->
            def br = CONTAINER.buildReq(path).GET()
            headers.each { k, v -> br.header(k, v) }
            HttpRequest req = br.build()
            def gottenStatus = null
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                gottenStatus = resp.statusCode()
                if (expectedStatus) {
                    assert gottenStatus == expectedStatus
                }
            }
            gottenStatus
        }

        doReq.call(200, '/hello.php')

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: false]]])

        doReq.call(200, '/hello.php', ['User-agent': 'dd-test-scanner-log-block'])

        // changes env during the the request. The new rem cfg path is transmitted on a
        // config_sync on rshutdown
        doReq.call(200, '/change_env.php?env=another-env')

        // enable appsec for another-env
        applyRemoteConfig(newTarget, [
                'datadog/2/ASM_FEATURES/asm_features_activation2/config': [asm: [enabled: true]]])

        def status = doReq.call(null, '/hello.php', ['User-agent': 'dd-test-scanner-log-block'])
        assert status == 403

        // last request reset the target to INITIAL_TARGET, where appsec is disabled
        // after this request, the target is reset to newTarget
        status = doReq.call(null, '/change_env.php?env=another-env&ini', ['User-agent': 'dd-test-scanner-log-block'])
        assert status == 200

        status = doReq.call(null, '/hello.php', ['User-agent': 'dd-test-scanner-log-block'])
        assert status == 403

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test identification auto user instrumentation'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM_FEATURES/asm_auto_user_instrum_identification/config': [auto_user_instrum: [mode: "identification"]],
        ])

        def trace = CONTAINER.traceFromRequest('/user_login_success_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == 'identification'

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test anonymized auto user instrumentation'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM_FEATURES/asm_auto_user_instrum_anonymization/config': [auto_user_instrum: [mode: "anonymization"]],
        ])

        def trace = CONTAINER.traceFromRequest('/user_login_success_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == 'anonymization'

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test disabled auto user instrumentation'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM_FEATURES/asm_auto_user_instrum_disabled/config': [auto_user_instrum: [mode: "disabled"]],
        ])

        def trace = CONTAINER.traceFromRequest('/user_login_success_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert !span.meta.containsKey('_dd.appsec.events.users.login.success.auto.mode')

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'test unknown auto user instrumentation'() {
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM_FEATURES/asm_auto_user_instrum_disabled/config': [auto_user_instrum: [mode: "unknown"]],
        ])

        def trace = CONTAINER.traceFromRequest('/user_login_success_automated.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert !span.meta.containsKey('_dd.appsec.events.users.login.success.auto.mode')

        dropRemoteConfig(INITIAL_TARGET)
    }

    private RemoteConfigRequest applyRemoteConfig(Target target, Map<String, Map> files) {
        CONTAINER.applyRemoteConfig(target, files).get()
    }

    private RemoteConfigRequest dropRemoteConfig(Target target) {
        applyRemoteConfig(target, [:])
    }

    @Test
    void 'test asm_dd_multiconfig'() {
        def doReq = { String userAgent, String expectedRuleId = null ->
            HttpRequest req = CONTAINER.buildReq('/hello.php')
                    .GET()
                    .header('User-Agent', userAgent)
                    .build()
            def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.body() == 'Hello world!'
            }
            def meta = trace.first().meta
            if (expectedRuleId) {
                assert meta.containsKey("_dd.appsec.json")
                assert meta."_dd.appsec.json".contains("\"rule\":{\"id\":\"${expectedRuleId}\"")
            } else {
                assert !meta.containsKey("_dd.appsec.json")
            }
        }

        def getConfigWithUserAgent = { String userAgent, String ruleId ->
                [
                        version: "2.2",
                        metadata: [rules_version: "2.71.8182"],
                        rules: [[
                                id: ruleId,
                                name: userAgent,
                                tags: [type: "attack_tool",category: "attack_attempt",],
                                conditions: [
                                        [
                                                parameters: [
                                                        inputs: [
                                                                [
                                                                        address: "server.request.headers.no_cookies",
                                                                        key_path: ["user-agent"]
                                                                ]
                                                        ],
                                                        regex: "^${userAgent}\\/v",
                                                ],
                                                operator: "match_regex",
                                        ]
                                ]
                        ]]
                ]
        }

        //There is no rule in the remote config, so no rule should be matched
        doReq.call('Arachni/v1')
        doReq.call('TechnoViking/v1')

        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ],
                'datadog/2/ASM_DD/rules_1/config': getConfigWithUserAgent('Arachni', 'str-000-001')
        ])

        //Only Arachni rule is in the remote config, so only Arachni rule should be matched
        doReq.call('Arachni/v1', 'str-000-001')
        doReq.call('TechnoViking/v1')


        //Add TechnoViking rule to the remote config alongside Arachni rule
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ],
                'datadog/2/ASM_DD/rules_1/config': getConfigWithUserAgent('Arachni', 'str-000-001'),
                'datadog/2/ASM_DD/rules_2/config': getConfigWithUserAgent('TechnoViking', 'str-000-002')
        ])

        //Both Arachni and TechnoViking rules are in the remote config, so both should be matched
        doReq.call('Arachni/v1', 'str-000-001')
        doReq.call('TechnoViking/v1', 'str-000-002')

        //Remove Arachni rule from the remote config
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ],
                'datadog/2/ASM_DD/rules_2/config': getConfigWithUserAgent('TechnoViking', 'str-000-002')
        ])

        //Only TechnoViking rule is in the remote config, so only TechnoViking rule should be matched
        doReq.call('Arachni/v1')
        doReq.call('TechnoViking/v1', 'str-000-002')

        //Replace TechnoViking rule with Arachni rule
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ],
                'datadog/2/ASM_DD/rules_2/config': getConfigWithUserAgent('Arachni', 'str-000-002')
        ])

        doReq.call('Arachni/v1', 'str-000-002')
        doReq.call('TechnoViking/v1')

        dropRemoteConfig(INITIAL_TARGET)
    }

    @Test
    void 'rules override can disable and re-enable a builtin rule'() {
        def doReq = { int expectedStatus ->
            HttpRequest req = CONTAINER.buildReq('/hello.php')
                    .GET()
                    .header('User-agent', 'dd-test-scanner-log-block')
                    .build()
            CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == expectedStatus
            }
        }

        // initially enabled
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]]
        ])
        doReq.call(403)

        // disable builtin rule 'ua0-600-56x' via rules_override
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_override/config': [
                        rules_override: [[
                                                 rules_target: [[ rule_id: 'ua0-600-56x' ]],
                                                 enabled: false
                                         ]]
                ]
        ])
        doReq.call(200)

        // clear overrides, re-enable default behavior
        applyRemoteConfig(INITIAL_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [asm: [enabled: true]],
                'datadog/2/ASM/custom_override/config': [
                        rules_override: []
                ]
        ])
        doReq.call(403)

        dropRemoteConfig(INITIAL_TARGET)
    }

}
