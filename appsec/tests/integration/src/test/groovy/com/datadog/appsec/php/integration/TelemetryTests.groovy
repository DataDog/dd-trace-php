package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Assumptions
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
            ) {
                @Override
                void configure() {
                    super.configure()
                    withEnv('RUST_LIB_BACKTRACE', '1')
                }
            }

    @BeforeAll
    static void beforeAll() {
        CONTAINER.flushProfilingData()
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

        // first request to start helper - triggers WAF init with legacy span metrics
        // Generally won't be covered by appsec because it doesn't receive RC data in time
        // for the response to config_sync
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        // Check legacy span metrics from WAF init are present
        def initSpan = trace[0]
        assert initSpan.metrics.'_dd.appsec.event_rules.loaded' > 0
        assert initSpan.metrics.'_dd.appsec.event_rules.error_count' == 0.0d
        assert initSpan.meta.'_dd.appsec.event_rules.errors' == '{}'

        RemoteConfigRequest rcReq = requestSup.get()
        assert rcReq != null, 'No RC request received'

        // request covered by Appsec; no attack
        trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // Check legacy span meta is present (metrics only on init)
        def span = trace[0]
        assert span.meta.'_dd.appsec.event_rules.version' =~ /\d+\.\d+\.\d+/

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
        TelemetryHelpers.Metric connSuccess
        TelemetryHelpers.Metric workerCount


        waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafInit = allSeries.find { it.name == 'waf.init' }
            // Rust helper has +1 tag (helper_runtime), C++ doesn't
            def useRust = System.getProperty('USE_HELPER_RUST') != null
            wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == (useRust ? 3 : 2) }
            wafReq2 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == (useRust ? 4 : 3) }
            connSuccess = allSeries.find { it.name == 'helper.connection_success' }
            workerCount = allSeries.find { it.name == 'helper.service_worker_count' }

            wafInit && wafReq1 && wafReq2 && connSuccess && workerCount
        }

        assert wafInit != null
        assert wafInit.namespace == 'appsec'
        assert wafInit.points[0][1] == 1.0
        assert 'success:true' in wafInit.tags
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

        assert connSuccess != null
        assert connSuccess.namespace == 'appsec'
        assert connSuccess.points[0][1] >= 1.0
        assert connSuccess.tags.find { it.startsWith('runtime_path:') } != null
        assert connSuccess.type == 'count'

        assert workerCount != null
        assert workerCount.namespace == 'appsec'
        assert workerCount.points[0][1] >= 1.0

        // Check helper_runtime tag: only Rust helper should have it
        if (System.getProperty('USE_HELPER_RUST') != null) {
            assert 'helper_runtime:rust' in wafInit.tags
            assert 'helper_runtime:rust' in wafReq1.tags
            assert 'helper_runtime:rust' in wafReq2.tags
            assert 'helper_runtime:rust' in workerCount.tags
            // connSuccess is from extension, not helper, so it doesn't have helper_runtime tag
        } else {
            // C++ helper should NOT have the helper_runtime tag in telemetry
            assert !wafInit.tags.any { it.startsWith('helper_runtime:') }
            assert !wafReq1.tags.any { it.startsWith('helper_runtime:') }
            assert !wafReq2.tags.any { it.startsWith('helper_runtime:') }
            assert !workerCount.tags.any { it.startsWith('helper_runtime:') }
        }
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

        TelemetryHelpers.Metric wafUpdates
        def messages = waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            def configErrors = allSeries.findAll { it.name == 'waf.config_errors' }
            wafUpdates = allSeries.find { it.name == 'waf.updates' }

            configErrors.size() >= 4 && wafUpdates
        }

       assert requestSup.get() != null

       // Make a request after RC is confirmed applied, check span has new version
       def trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
           assert resp.statusCode() == 200
       }
       def span = trace[0]
       assert span.meta.'_dd.appsec.event_rules.version' == '1.1.1'

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

        assert wafUpdates != null
        assert wafUpdates.namespace == 'appsec'
        assert wafUpdates.points[0][1] >= 1.0d
        assert 'success:true' in wafUpdates.tags
        assert 'event_rules_version:1.1.1' in wafUpdates.tags
        assert wafUpdates.tags.find { it.startsWith('waf_version:') } != null
        assert wafUpdates.type == 'count'

        // Check helper_runtime tag: only Rust helper should have it
        if (System.getProperty('USE_HELPER_RUST') != null) {
            assert 'helper_runtime:rust' in wafUpdates.tags
            series.each { assert 'helper_runtime:rust' in it.tags }
        } else {
            // C++ helper should NOT have the helper_runtime tag in telemetry
            assert !wafUpdates.tags.any { it.startsWith('helper_runtime:') }
            series.each { assert !it.tags.any { tag -> tag.startsWith('helper_runtime:') } }
        }
    }

    @Test
    @Order(3)
    void 'telemetry log for failed application of config'() {
        def request = CONTAINER.buildReq('/hello.php').GET().build()
        CONTAINER.traceFromRequest(request, ofString()) { HttpResponse<String> resp ->
            assert resp.body().size() > 0
        }

        def requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_DATA/bad_config/config': [
                        rules_data: 'BAD VALUE'
                ],
                'datadog/2/ASM_DD/bad_rule/config': [
                        version: '2.1',
                        metadata: [rules_version: '1.1.1'],
                        rules: [[
                                        id: 'bad_rule',
                                        name: 'Name of the bad rule',
                                ]
                        ]
                ],
                'datadog/2/ASM_DD/warning_rule/config': [
                        version: '2.1',
                        metadata: [rules_version: '1.1.1'],
                        rules: [[
                                        id: 'bad_condition_rule',
                                        name: 'Bad condition rule',
                                        tags: [
                                                type: 'block_ip',
                                                category: 'attack_attempt'
                                        ],
                                        conditions: [[
                                                             parameters: [:],
                                                             operator: 'unknown_operator'
                                                     ]],
                                ]
                        ]
                ]
        ])

        def messages = waitForTelemetryLogs(30) { List<TelemetryHelpers.Logs> logs ->
            def relevantLogs = logs.collectMany { it.logs.findAll { it.tags.contains('log_type:rc::') } }
            relevantLogs.size() >= 3
        }.collectMany { it.logs }

        assert requestSup.get() != null

        assert messages.size() >= 3
        assert messages.any {
            it.level == 'ERROR' &&
                    it.message == "bad cast, expected 'array', obtained 'string'" &&
                    it.parsedTags == [
                    log_type: 'rc::asm_data::diagnostic',
                    appsec_config_key: 'rules_data',
                    rc_config_id: 'bad_config',
            ]
        }
        assert messages.any {
            it.level == 'ERROR' &&
                    it.message == "{\"missing key 'conditions'\":[\"bad_rule\"]}" &&
                    it.parsedTags == [
                    log_type: 'rc::asm_dd::diagnostic',
                    appsec_config_key: 'rules',
                    rc_config_id: 'bad_rule',
            ]
        }
        assert messages.any {
            it.level == 'WARN' &&
                    it.message == "{\"unknown operator: 'unknown_operator'\":[\"bad_condition_rule\"]}" &&
                    it.parsedTags == [
                    log_type: 'rc::asm_dd::diagnostic',
                    appsec_config_key: 'rules',
                    rc_config_id: 'warning_rule',
            ]
        }
    }

    @Test
    @Order(4)
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

    private static List<TelemetryHelpers.Logs> waitForTelemetryLogs(int timeoutSec, Closure<Boolean> cl) {
        waitForTelemetryData(timeoutSec, cl, TelemetryHelpers.Logs)
    }

    private static <T> List<T> waitForTelemetryData(int timeoutSec, Closure<Boolean> cl, Class<T> cls) {
        List<T> messages = []
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
    @Order(5)
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

        // Check helper_runtime tag: only Rust helper should have it
        def raspMetrics = [wafReq1, lfiEval, lfiMatch, lfiTimeout, ssrfEval, ssrfMatch, ssrfTimeout]
        if (System.getProperty('USE_HELPER_RUST') != null) {
            raspMetrics.each { assert 'helper_runtime:rust' in it.tags }
        } else {
            // C++ helper should NOT have the helper_runtime tag in telemetry
            raspMetrics.each { assert !it.tags.any { tag -> tag.startsWith('helper_runtime:') } }
        }
    }

    /**
     * This test takes a long time (around 10-12 seconds) because the metric
     * interval is hardcoded to 10 seconds in the metrics.rs.
     */
    @Test
    @Order(5)
    void 'User tracking telemetry is generated'() {
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
        trace = CONTAINER.traceFromRequest('/multiple_user_tracking_events.php?success=2&failure=3') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert trace.traceId != null

        TelemetryHelpers.Metric loginSuccess
        TelemetryHelpers.Metric loginFailure

        waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            println allSeries
            loginSuccess = allSeries.find{ it.name == 'sdk.event' && 'event_type:login_success' in it.tags}
            loginFailure = allSeries.find{ it.name == 'sdk.event' && 'event_type:login_failure' in it.tags}

             loginSuccess && loginFailure
        }

        assert loginSuccess != null
        assert loginSuccess.namespace == 'appsec'
        assert loginSuccess.points[0][1] >= 2.0
        assert loginSuccess.tags.find { it.startsWith('sdk_version:v2') } != null
        assert loginSuccess.type == 'count'

        assert loginFailure != null
        assert loginFailure.namespace == 'appsec'
        assert loginFailure.points[0][1] == 3.0
        assert loginFailure.tags.find { it.startsWith('sdk_version:v2') } != null
        assert loginFailure.type == 'count'
    }

    /**
     * This test verifies that when input is truncated (strings > 4096 chars),
     * the waf.requests metric includes the input_truncated:true tag.
     */
    @Test
    @Order(6)
    void 'Input truncation telemetry is generated'() {
        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // first request to start helper
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        RemoteConfigRequest rcReq = requestSup.get()
        assert rcReq != null, 'No RC request received'

        // request with a very long query string (> 4096 chars) to trigger truncation
        def longString = 'A' * 5000
        HttpRequest req = CONTAINER.buildReq("/hello.php?long_param=${longString}")
                .GET().build()
        trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().size() > 0
        }
        assert trace.traceId != null

        TelemetryHelpers.Metric wafReqTruncated

        waitForMetrics(30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafReqTruncated = allSeries.find {
                it.name == 'waf.requests' && 'input_truncated:true' in it.tags
            }

            wafReqTruncated != null
        }

        assert wafReqTruncated != null
        assert wafReqTruncated.namespace == 'appsec'
        assert wafReqTruncated.points[0][1] >= 1.0
        assert 'input_truncated:true' in wafReqTruncated.tags
        assert wafReqTruncated.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafReqTruncated.tags.find { it.startsWith('waf_version:') } != null
        assert wafReqTruncated.type == 'count'

        // Check helper_runtime tag: only Rust helper should have it
        if (System.getProperty('USE_HELPER_RUST') != null) {
            assert 'helper_runtime:rust' in wafReqTruncated.tags
        } else {
            // C++ helper should NOT have the helper_runtime tag in telemetry
            assert !wafReqTruncated.tags.any { it.startsWith('helper_runtime:') }
        }
    }

    /**
     * This test verifies that helper-rust errors are properly sent to telemetry
     * with backtraces. It sends an invalid message to the helper which triggers
     * an error with backtrace.
     *
     * This test only runs when USE_HELPER_RUST is set (Rust helper implementation).
     */
    @Test
    @Order(7)
    void 'helper error telemetry includes backtrace'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null)

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // first request to start helper and establish connection
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        RemoteConfigRequest rcReq = requestSup.get()
        assert rcReq != null, 'No RC request received'

        // request covered by Appsec to ensure we have an active connection
        trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        // send invalid message to trigger error with backtrace
        CONTAINER.traceFromRequest('/send_invalid_msg.php') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }

        def messages = waitForTelemetryLogs(30) { List<TelemetryHelpers.Logs> logs ->
            def relevantLogs = logs.collectMany { it.logs.findAll { it.tags.contains('log_type:helper::logged_error') } }
            !relevantLogs.empty
        }.collectMany { it.logs }

        def errorLog = messages.find { it.tags?.contains('log_type:helper::logged_error') }

        assert errorLog != null : "Expected to find a log with log_type:helper::client_error. " +
                "All logs: ${messages}"
        assert errorLog.level == 'ERROR' : "Expected ERROR level, got ${errorLog.level}"
        assert errorLog.message?.contains('unknown command') || errorLog.message?.contains('invalid_command') :
                "Expected error message about unknown/invalid command, got: ${errorLog.message}"

        // back trace
        assert errorLog.stack_trace != null : "Expected stack_trace to be present"
        assert errorLog.stack_trace.length() > 0 : "Expected stack_trace to be non-empty"

        // check that the backtrace contains some typical Rust stack frame indicators
        def hasStackFrameIndicators = errorLog.stack_trace.contains('::') ||
                errorLog.stack_trace.contains('.rs:') ||
                errorLog.stack_trace =~ /at \d+:\d+/ ||
                errorLog.stack_trace =~ /\d+: / ||
                errorLog.stack_trace =~ /:\d+$/

        assert hasStackFrameIndicators :
                "Expected backtrace with stack frame indicators (::, .rs:, line numbers), got: ${errorLog.stack_trace}"

        // This test only runs for Rust helper, so verify helper_runtime:rust tag is present in logs
        assert errorLog.tags?.contains('helper_runtime:rust') :
                "Expected helper_runtime:rust tag in log tags, got: ${errorLog.tags}"

        CONTAINER.clearTraces()
    }
}
