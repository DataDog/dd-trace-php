package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.util.function.Supplier

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@DisabledIf('isDisabled')
class TelemetryTests {
    static boolean disabled = variant.contains('zts') || phpVersion != '8.3'

    private static final Target RC_TARGET = new Target('appsec_int_tests', 'integration', '')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    @BeforeAll
    static void beforeAll() {
        org.testcontainers.containers.Container.ExecResult res = CONTAINER.execInContainer(
                'bash', '-c',
                '''sed -e '/appsec.enabled/d' -e '/appsec.rules=/d' /etc/php/php.ini > /etc/php/php-rc.ini;
                   kill -9 `pgrep php-fpm`;
                   export  DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=1;
                   php-fpm -y /etc/php-fpm.conf -c /etc/php/php-rc.ini''')
        assert res.exitCode == 0
    }

    /**
     * This test takes a long time (around 10-12 seconds) because the metric
     * interval is hardcoded to 10 seconds in the metrics.rs.
     */
    @Test
    @Order(1)
    void 'telemetry data is received'() {
        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // first request to start helper
        // Generally won't be covered by appsec because it doesn't receive RC data in time
        // for the response to config_sync
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        RemoteConfigRequest rcReq = requestSup.get()
        assert rcReq != null, 'No RC request received'

        // request covered by Appsec; no attack
        trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // now do an attack
        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .header('User-Agent', 'Arachni/v1').GET().build()
        trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().size() > 0
        }
        assert trace.traceId != null

        TelemetryHelpers.Metric wafInit
        TelemetryHelpers.Metric wafReq1
        TelemetryHelpers.Metric wafReq2

        waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafInit = allSeries.find { it.name == 'waf.init' }
            wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 2 }
            wafReq2 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 3 }

            wafInit && wafReq1 && wafReq2
        }

        assert wafInit != null
        assert wafInit.namespace == 'appsec'
        assert wafInit.points[0][1] == 1.0
        assert 'success:true' in wafInit.tags
        assert wafInit.tags.size() == 3
        assert wafInit.type == 'count'
        assert wafInit.interval == 10

        assert wafReq1 != null
        assert wafReq1.namespace == 'appsec'
        assert wafReq1.points[0][1] >= 1.0
        assert wafReq1.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafReq1.tags.find { it.startsWith('waf_version:') } != null
        assert wafReq1.type == 'count'

        assert wafReq2 != null
        assert 'rule_triggered:true' in wafReq2.tags
        assert wafReq2.points[0][1] >= 1.0
    }

    @Test
    @Order(2)
    void 'telemetry data for failed ddwaf updates'() {
        def requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ],
                'datadog/2/ASM_DATA/bad_data/config': [
                        rules_data: [
                                [
                                        id: 'bad_blocked_ips',
                                        type: 'bad_type',
                                        data: [
                                                [foo: 'bar']
                                        ]
                                ]
                        ]

                ],
                'datadog/2/ASM/custom_user_cfg_2/config': [
                        actions: [[
                                          id: 'blocked_ips',
                                          type: 'bad_block_id',
                                          parameters: [:]
                                  ]],
                        custom_rules: [[
                                               id: 'custom_rule_id',
                                               name: 'Bad custom rule id',
                                               tags: [],
                                               conditions: [[
                                                                    parameters: [:],
                                                                    operator: 'bad_operator'
                                                            ]],
                                               transformers: ['values_only'],
                                               on_match: ['block_custom']

                                       ], // generates just a warning; not reported
                                       [:] // generates an actual error
                        ],
                        exclusions: [[ id: 'bad_exclusion' ]],
                        rules_override: [[ foo: 'bar' ], [ bar: 'foo' ]],
                ],
                'datadog/2/ASM_DD/full_cfg/config':
                        [
                                version: '2.1',
                                metadata: [rules_version: '1.1.1'],
                                rules: [[
                                                id: 'blk-001-001',
                                                name: 'Block IP Addresses',
                                                tags: [
                                                        type: 'block_ip',
                                                        category: 'attack_attempt'
                                                ],
                                                conditions: [[
                                                                     parameters: [
                                                                             inputs: [[ address: 'http.client_ip' ]],
                                                                             data: 'blocked_ips',
                                                                     ],
                                                                     operator: 'ip_match'
                                                             ]],
                                                transformers: [],
                                                on_match: ['block']
                                        ],
                                        [
                                                id: 'bad rule'
                                        ]
                                ]
                        ]
        ])

        def messages = waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages
                    .collectMany { it.series }
                    .findAll {
                        it.name == 'waf.config_errors'
                    }

            allSeries.size() >= 4
        }

       assert requestSup.get() != null

       def series = messages
                .collectMany { it.series }
                .findAll {
                    it.name == 'waf.config_errors'
                }

        series.each {
            assert 'event_rules_version:1.1.1' in it.tags
            assert 'scope:item' in it.tags
        }

        def rulesOverride = series.find {
            it.namespace == 'appsec' && 'config_key:rules_override' in it.tags
        }
        def exclusions = series.find {
            it.namespace == 'appsec' && 'config_key:exclusions' in it.tags
        }
        def customRules = series.find {
            it.namespace == 'appsec' && 'config_key:custom_rules' in it.tags
        }
        def rules = series.find {
            it.namespace == 'appsec' && 'config_key:rules' in it.tags
        }
        def data = series.find {
            it.namespace == 'appsec' && 'config_key:rules_data' in it.tags
        }

        assert rulesOverride.points[0][1] == 2.0d
        assert exclusions.points[0][1] == 1.0d
        assert customRules.points[0][1] == 1.0d
        assert rules.points[0][1] == 1.0d
        assert data.points[0][1] == 1.0d
    }

    @Test
    @Order(3)
    void 'telemetry reflects the loading of a new integration'() {
        def trace = CONTAINER.traceFromRequest('/custom_integrations.php?integrations[]=redis') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }

        List<TelemetryHelpers.IntegrationEntry> allIntegrations = []
        boolean foundRedis = false
        waitForIntegrations(30) { List<TelemetryHelpers.WithIntegrations> messages ->
            allIntegrations.addAll(messages.collectMany { it.integrations })
            foundRedis = allIntegrations.find { it.name == 'phpredis' && it.enabled == Boolean.TRUE } != null
        }
        assert foundRedis

        trace = CONTAINER.traceFromRequest('/custom_integrations.php?integrations[]=exec&integrations[]=redis') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }
        allIntegrations = []
        foundRedis = false
        boolean foundExec = false
        waitForIntegrations(15) { List<TelemetryHelpers.WithIntegrations> messages ->
            allIntegrations.addAll(messages.collectMany { it.integrations })
            foundRedis = allIntegrations.find { it.name == 'phpredis' && it.enabled == Boolean.TRUE } != null
            foundExec = allIntegrations.find { it.name == 'exec' && it.enabled == Boolean.TRUE } != null
        }

        assert !foundRedis
        assert foundExec
    }

    private static List<TelemetryHelpers.GenerateMetrics> waitForMetrics(int timeoutSec, Closure<Boolean> cl) {
        waitForTelemetryData(timeoutSec, cl, TelemetryHelpers.GenerateMetrics)
    }

    private static List<TelemetryHelpers.WithIntegrations> waitForIntegrations(int timeoutSec, Closure<Boolean> cl) {
        waitForTelemetryData(timeoutSec, cl, TelemetryHelpers.WithIntegrations)
    }

    private static List<TelemetryHelpers.GenerateMetrics> waitForTelemetryData(int timeoutSec, Closure<Boolean> cl, Class cls) {
        List<TelemetryHelpers.GenerateMetrics> messages = []
        def deadline = System.currentTimeSeconds() + timeoutSec
        def lastHttpReq = System.currentTimeSeconds() - 6
        while (System.currentTimeSeconds() < deadline) {
            if (System.currentTimeSeconds() - lastHttpReq > 5) {
                lastHttpReq = System.currentTimeSeconds()
                // used to flush global (not request-bound) telemetry metrics
                def request = CONTAINER.buildReq('/hello.php').GET().build()
                def trace = CONTAINER.traceFromRequest(request, ofString()) { HttpResponse<String> resp ->
                    assert resp.body().size() > 0
                }
            }
            def telData = CONTAINER.drainTelemetry(500)
            messages.addAll(
                    TelemetryHelpers.filterMessages(telData, cls))
            if (cl.call(messages)) {
                break
            }
        }
        messages
    }

    /**
     * This test takes a long time (around 10-12 seconds) because the metric
     * interval is hardcoded to 10 seconds in the metrics.rs.
     */
    @Test
    @Order(4)
    void 'Rasp telemetry is generated'() {
        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // first request to start helper
        // Generally won't be covered by appsec because it doesn't receive RC data in time
        // for the response to config_sync
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        RemoteConfigRequest rcReq = requestSup.get()
        assert rcReq != null, 'No RC request received'

        // request covered by Appsec
        trace = CONTAINER.traceFromRequest('/multiple_rasp.php?path=../somefile&other=../otherfile&domain=169.254.169.254') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert trace.traceId != null

        TelemetryHelpers.Metric wafReq1
        TelemetryHelpers.Metric lfiEval
        TelemetryHelpers.Metric ssrfEval
        TelemetryHelpers.Metric lfiMatch
        TelemetryHelpers.Metric ssrfMatch
        TelemetryHelpers.Metric lfiTimeout
        TelemetryHelpers.Metric ssrfTimeout

        waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 2 }
            lfiEval = allSeries.find{ it.name == 'rasp.rule.eval' && 'rule_type:lfi' in it.tags}
            lfiMatch = allSeries.find{ it.name == 'rasp.rule.match' && 'rule_type:lfi' in it.tags}
            lfiTimeout = allSeries.find{ it.name == 'rasp.timeout' && 'rule_type:lfi' in it.tags}
            ssrfEval = allSeries.find{ it.name == 'rasp.rule.eval' && 'rule_type:ssrf' in it.tags}
            ssrfMatch = allSeries.find{ it.name == 'rasp.rule.match' && 'rule_type:ssrf' in it.tags}
            ssrfTimeout = allSeries.find{ it.name == 'rasp.timeout' && 'rule_type:ssrf' in it.tags}

             wafReq1 && lfiEval && ssrfEval && lfiMatch && ssrfMatch && lfiTimeout && ssrfTimeout
        }

        assert wafReq1 != null
        assert wafReq1.namespace == 'appsec'
        assert wafReq1.points[0][1] >= 1.0
        assert wafReq1.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafReq1.tags.find { it.startsWith('waf_version:') } != null
        assert wafReq1.type == 'count'

        assert lfiEval != null
        assert lfiEval.namespace == 'appsec'
        assert lfiEval.points[0][1] == 3.0
        assert lfiEval.type == 'count'
        assert lfiEval.tags.find { it.startsWith('waf_version:') } != null

        assert lfiMatch != null
        assert lfiMatch.namespace == 'appsec'
        assert lfiMatch.points[0][1] == 2.0
        assert lfiMatch.type == 'count'
        assert lfiMatch.tags.find { it.startsWith('waf_version:') } != null

        assert lfiTimeout != null
        assert lfiTimeout.namespace == 'appsec'
        assert lfiTimeout.points[0][1] == 0.0
        assert lfiTimeout.type == 'count'
        assert lfiTimeout.tags.find { it.startsWith('waf_version:') } != null

        assert ssrfEval != null
        assert ssrfEval.namespace == 'appsec'
        assert ssrfEval.points[0][1] == 2.0
        assert ssrfEval.type == 'count'
        assert ssrfEval.tags.find { it.startsWith('waf_version:') } != null

        assert ssrfMatch != null
        assert ssrfMatch.namespace == 'appsec'
        assert ssrfMatch.points[0][1] == 1.0
        assert ssrfMatch.type == 'count'
        assert ssrfMatch.tags.find { it.startsWith('waf_version:') } != null

        assert ssrfTimeout != null
        assert ssrfTimeout.namespace == 'appsec'
        assert ssrfTimeout.points[0][1] == 0.0
        assert ssrfTimeout.type == 'count'
        assert ssrfTimeout.tags.find { it.startsWith('waf_version:') } != null
    }
}
