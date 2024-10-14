package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_agent.rem_cfg.Capability
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigResponse
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import groovy.json.JsonOutput
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.nio.charset.StandardCharsets
import java.time.Instant

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.junit.jupiter.api.Assumptions.assumeTrue
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
class RemoteConfigTests {
    static boolean disabled = variant.contains('zts') || phpVersion != '8.3'

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
                   export _DD_DEBUG_SIDECAR_RC_POLL_INTERVAL_MILLIS=1000 DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=1 DD_ENV=;
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

    private RemoteConfigRequest applyRemoteConfig(Target target, Map<String, Map> files) {
        Map<String, byte[]> encodedFiles = files
                .findAll { it.value != null }
                .collectEntries {
                    [
                            it.key,
                            JsonOutput.toJson(it.value).getBytes(StandardCharsets.UTF_8)
                    ]
                }
        long newVersion = Instant.now().epochSecond
        def rcr = new RemoteConfigResponse()
        rcr.clientConfigs = files.keySet() as List
        rcr.targetFiles = encodedFiles.collect {
            new RemoteConfigResponse.TargetFile(
                    path: it.key,
                    raw: new String(
                            Base64.encoder.encode(it.value),
                            StandardCharsets.ISO_8859_1)
            )
        }
        rcr.targets = new RemoteConfigResponse.Targets(
                signatures: [],
                targetsSigned: new RemoteConfigResponse.Targets.TargetsSigned(
                        type: 'root',
                        custom: new RemoteConfigResponse.Targets.TargetsSigned.TargetsCustom(
                                opaqueBackendState: 'ABCDEF'
                        ),
                        specVersion:'1.0.0',
                        expires: Instant.parse('2030-01-01T00:00:00Z'),
                        version: newVersion,
                        targets: encodedFiles.collectEntries {
                            [
                                    it.key,
                                    new RemoteConfigResponse.Targets.ConfigTarget(
                                            hashes: [sha256: RemoteConfigResponse.sha256(it.value).toString(16).padLeft(64, '0')],
                                            length: it.value.size(),
                                            custom: new RemoteConfigResponse.Targets.ConfigTarget.ConfigTargetCustom(
                                                    version: newVersion
                                            )
                                    )
                            ]
                        }
                ),
        )

        CONTAINER.setNextRCResponse(target, rcr)
        CONTAINER.waitForRCVersion(target, newVersion, 5_000)
    }

    RemoteConfigRequest dropRemoteConfig(Target target) {
        applyRemoteConfig(target, [:])
    }

}
