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
import java.nio.charset.StandardCharsets
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


        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafInit = allSeries.find { it.name == 'waf.init' }
            def useRust = System.getProperty('USE_HELPER_RUST') != null
            if (useRust) {
                // RFC-1012: all boolean tags must be emitted unconditionally, so distinguish
                // requests by tag value rather than tag count.
                wafReq1 = allSeries.find { it.name == 'waf.requests' && 'rule_triggered:false' in it.tags }
                wafReq2 = allSeries.find { it.name == 'waf.requests' && 'rule_triggered:true' in it.tags }
            } else {
                // C++ helper: still uses tag-count-based detection (not yet RFC-1012 compliant)
                wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 2 }
                wafReq2 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 3 }
            }
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
        // RFC-1012: boolean tags must be present even when false (Rust helper only)
        if (System.getProperty('USE_HELPER_RUST') != null) {
            assert 'rule_triggered:false' in wafReq1.tags
            assert 'request_blocked:false' in wafReq1.tags
            assert 'waf_timeout:false' in wafReq1.tags
            assert 'input_truncated:false' in wafReq1.tags
            assert 'waf_error:false' in wafReq1.tags
        }

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
        def messages = TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
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
            if (System.getProperty('USE_HELPER_RUST') != null) {
                assert 'action:update' in it.tags
            }
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

        def messages = TelemetryHelpers.waitForLogs(CONTAINER, 30) { List<TelemetryHelpers.Logs> logs ->
            def relevantLogs = logs.collectMany { it.logs.findAll { it.tags.contains('log_type:rc::') } }
            relevantLogs.size() >= 3 && relevantLogs.any { it.tags.contains('rc_config_id:bad_config') }
        }.collectMany { it.logs }

        assert requestSup.get() != null

        assert messages.size() >= 3
        assert messages.any {
            it.level == 'ERROR' &&
                    it.message == "bad cast, expected 'array', obtained 'string'" &&
                    it.parsedTags.log_type == 'rc::asm_data::diagnostic' &&
                    it.parsedTags.appsec_config_key == 'rules_data' &&
                    it.parsedTags.rc_config_id == 'bad_config'
        }
        assert messages.any {
            it.level == 'ERROR' &&
                    it.message == "{\"missing key 'conditions'\":[\"bad_rule\"]}" &&
                    it.parsedTags.log_type == 'rc::asm_dd::diagnostic' &&
                    it.parsedTags.appsec_config_key == 'rules' &&
                    it.parsedTags.rc_config_id == 'bad_rule'
        }
        assert messages.any {
            it.level == 'WARN' &&
                    it.message == "{\"unknown operator: 'unknown_operator'\":[\"bad_condition_rule\"]}" &&
                    it.parsedTags.log_type == 'rc::asm_dd::diagnostic' &&
                    it.parsedTags.appsec_config_key == 'rules' &&
                    it.parsedTags.rc_config_id == 'warning_rule'
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
        TelemetryHelpers.waitForIntegrations(CONTAINER, 30) { List<TelemetryHelpers.WithIntegrations> messages ->
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
        TelemetryHelpers.waitForIntegrations(CONTAINER, 15) { List<TelemetryHelpers.WithIntegrations> messages ->
            allIntegrations.addAll(messages.collectMany { it.integrations })
            foundRedis = allIntegrations.find { it.name == 'phpredis' && it.enabled == Boolean.TRUE } != null
            foundExec = allIntegrations.find { it.name == 'exec' && it.enabled == Boolean.TRUE } != null
        }

        assert !foundRedis
        assert foundExec
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

        def useRust = System.getProperty('USE_HELPER_RUST') != null
        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            if (useRust) {
                // RFC-1012: boolean tags always emitted; use tag value, not tag count
                wafReq1 = allSeries.find { it.name == 'waf.requests' && 'rule_triggered:false' in it.tags }
            } else {
                wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 2 }
            }
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
            // RFC-1012: event_rules_version must be present on all RASP per-rule metrics
            def raspRuleMetrics = [lfiEval, lfiMatch, lfiTimeout, ssrfEval, ssrfMatch, ssrfTimeout]
            raspRuleMetrics.each { metric ->
                assert metric.tags.find { it.startsWith('event_rules_version:') } != null :
                    "event_rules_version tag missing on ${metric.name} (tags: ${metric.tags})"
            }
            // SSRF is triggered from a pre-hook (before the network call), so variant is "request"
            [ssrfEval, ssrfMatch, ssrfTimeout].each { metric ->
                assert 'rule_variant:request' in metric.tags :
                    "rule_variant:request missing on ${metric.name} (tags: ${metric.tags})"
            }
            // LFI has no variant — tag must be absent (sidecar rejects empty tag values)
            [lfiEval, lfiMatch, lfiTimeout].each { metric ->
                assert !metric.tags.any { it.startsWith('rule_variant:') } :
                    "unexpected rule_variant tag on ${metric.name} (tags: ${metric.tags})"
            }
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

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
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

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
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
     * Verifies that _dd.appsec.waf.duration and _dd.appsec.waf.duration_ext are emitted as
     * span metrics (ms) and that appsec.waf.duration / appsec.waf.duration_ext are emitted
     * as DDSketch distributions (µs). Cross-checks consistency: each span metric value
     * (ms × 1000 → µs) must fall inside a populated bin of the corresponding distribution.
     *
     * This test only applies to the Rust helper (distributions not implemented elsewhere).
     */
    @Test
    @Order(7)
    void 'waf duration span metrics and distributions are consistent'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'appsec.waf.duration distributions are only implemented on the Rust helper')

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert requestSup.get() != null

        // This span's WAF duration metrics will be cross-checked against the distributions.
        def trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        TelemetryHelpers.DistributionMetric wafDuration
        TelemetryHelpers.DistributionMetric wafDurationExt

        TelemetryHelpers.waitForDistributions(CONTAINER, 30) { List<TelemetryHelpers.GenerateDistributions> messages ->
            def allSeries = messages.collectMany { it.series }
            wafDuration = wafDuration ?: allSeries.find { it.name == 'waf.duration' }
            wafDurationExt = wafDurationExt ?: allSeries.find { it.name == 'waf.duration_ext' }
            wafDuration != null && wafDurationExt != null
        }

        assert wafDuration != null : 'waf.duration distribution metric not found'
        assert wafDuration.namespace == 'appsec'
        assert wafDuration.tags.find { it.startsWith('waf_version:') } != null
        assert wafDuration.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafDuration.count >= 1.0

        assert wafDurationExt != null : 'waf.duration_ext distribution metric not found'
        assert wafDurationExt.namespace == 'appsec'
        assert wafDurationExt.tags.find { it.startsWith('waf_version:') } != null
        assert wafDurationExt.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafDurationExt.count >= 1.0

        def span = trace[0]
        def durationUs = span.metrics.'_dd.appsec.waf.duration'
        assert durationUs != null && durationUs > 0.0d :
            '_dd.appsec.waf.duration span metric must be > 0'
        def durationExtUs = span.metrics.'_dd.appsec.waf.duration_ext'
        assert durationExtUs != null && durationExtUs > 0.0d :
            '_dd.appsec.waf.duration_ext span metric must be > 0'

        assert durationExtUs >= durationUs :
            '_dd.appsec.waf.duration_ext must be >= .duration'

        // Both waf.duration span metric and distribution are in µs.
        assert wafDuration.countForBinContaining(durationUs) != null :
            "span metric value ${durationUs} µs not found in any " +
            "waf.duration distribution bin; distribution: ${wafDuration}"
        // Both waf.duration_ext span metric and distribution are in µs.
        assert wafDurationExt.countForBinContaining(durationExtUs) != null :
            "span metric value ${durationExtUs} µs not found in any " +
            "waf.duration_ext distribution bin; distribution: ${wafDurationExt}"
    }

    /**
     * This test verifies that helper-rust errors are properly sent to telemetry
     * with backtraces. It sends an invalid message to the helper which triggers
     * an error with backtrace.
     *
     * This test only runs when USE_HELPER_RUST is set (Rust helper implementation).
     */
    @Test
    @Order(8)
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

        def messages = TelemetryHelpers.waitForLogs(CONTAINER, 30) { List<TelemetryHelpers.Logs> logs ->
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

        // require symbolized Rust frames, not only raw/unknown frame placeholders
        assert errorLog.stack_trace.contains('.rs:') :
                "Expected stack_trace with Rust source references (.rs:line), got: ${errorLog.stack_trace}"

        // This test only runs for Rust helper, so verify helper_runtime:rust tag is present in logs
        assert errorLog.tags?.contains('helper_runtime:rust') :
                "Expected helper_runtime:rust tag in log tags, got: ${errorLog.tags}"

        CONTAINER.clearTraces()
    }

    @Test
    @Order(8)
    void 'telemetry log for malformed RC config JSON'() {
        def enableSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        def trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null
        assert enableSup.get() != null

        def malformedJson = 'this is not valid JSON {][ at all'.getBytes(StandardCharsets.UTF_8)

        def requestSup = CONTAINER.applyRemoteConfigRaw(RC_TARGET, [
                'datadog/2/ASM_DD/malformed_config/config': malformedJson
        ])

        // Wait for telemetry logs with rc::asm_dd::exception
        def messages = TelemetryHelpers.waitForLogs(CONTAINER, 30) { List<TelemetryHelpers.Logs> logs ->
            def relevantLogs = logs.collectMany {
                it.logs.findAll { it.tags?.contains('log_type:rc::asm_dd::exception') }
            }
            !relevantLogs.empty
        }.collectMany { it.logs }

        assert requestSup.get() != null

        def exceptionLog = messages.find {
            it.tags?.contains('log_type:rc::asm_dd::exception') &&
            it.tags?.contains('rc_config_id:malformed_config')
        }

        assert exceptionLog != null : "Expected to find rc::asm_dd::exception log. " +
                "All logs: ${messages.collect { [identifier: it.identifier, tags: it.tags, message: it.message] }}"
        assert exceptionLog.level == 'ERROR' : "Expected ERROR level, got ${exceptionLog.level}"
        assert exceptionLog.message?.contains('malformed') ||
               exceptionLog.message?.contains('parse') ||
               exceptionLog.message?.contains('JSON') ||
               exceptionLog.message?.contains('Failed to apply config') :
                "Expected error message about parse/JSON failure, got: ${exceptionLog.message}"
    }

    /**
     * RFC-1012: all boolean tags on appsec.waf.requests must be emitted unconditionally,
     * including when the value is false. This verifies a clean (non-attack, non-timeout,
     * non-truncated) request still carries all boolean tags.
     *
     * This test only applies to the Rust helper, which implements RFC-1012.
     */
    @Test
    @Order(9)
    void 'waf requests boolean tags are emitted unconditionally'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'RFC-1012 boolean tag compliance is only enforced on the Rust helper')

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // Start helper
        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert requestSup.get() != null

        // Clean request: no attack, no truncation — all boolean tags must still be present
        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        TelemetryHelpers.Metric wafReq

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafReq = allSeries.find { it.name == 'waf.requests' && 'rule_triggered:false' in it.tags }
            wafReq != null
        }

        assert wafReq != null : 'waf.requests metric with rule_triggered:false not found — ' +
                'boolean tags must be emitted even when false (RFC-1012)'
        assert wafReq.namespace == 'appsec'
        assert wafReq.type == 'count'
        assert wafReq.tags.find { it.startsWith('waf_version:') } != null
        assert wafReq.tags.find { it.startsWith('event_rules_version:') } != null
        assert 'rule_triggered:false' in wafReq.tags
        assert 'request_blocked:false' in wafReq.tags
        assert 'waf_timeout:false' in wafReq.tags
        assert 'input_truncated:false' in wafReq.tags
        assert 'waf_error:false' in wafReq.tags
        assert 'rate_limited:false' in wafReq.tags
    }


    /**
     * Verifies that appsec.waf.requests is tagged request_blocked:true when the WAF
     * returns a block_request action and false otherwise
     */
    @Test
    @Order(10)
    void 'waf requests request_blocked tag is true on blocking attack'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'request_blocked tag is only emitted unconditionally by the Rust helper')

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert requestSup.get() != null

        // Blocking request: 80.80.80.80 hits the recommended.json IP blocklist rule
        // (on_match: ["block"]). The WAF returns a block_request action.
        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 403
        }

        TelemetryHelpers.Metric wafReqBlocked
        TelemetryHelpers.Metric wafReqNotBlocked

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            wafReqBlocked = allSeries.find {
                it.name == 'waf.requests' &&
                        'rule_triggered:true' in it.tags &&
                        'request_blocked:true' in it.tags
            }
            // from the 1st request
            wafReqNotBlocked = allSeries.find {
                it.name == 'waf.requests' && 'request_blocked:false' in it.tags
            }
            wafReqBlocked != null && wafReqNotBlocked != null
        }

        assert wafReqBlocked != null : 'waf.requests metric with request_blocked:true not found ' +
                '-- helper failed to detect the WAF block action'
        assert wafReqBlocked.namespace == 'appsec'
        assert wafReqBlocked.type == 'count'
        assert 'rule_triggered:true' in wafReqBlocked.tags
        assert 'request_blocked:true' in wafReqBlocked.tags

        assert wafReqNotBlocked != null : 'waf.requests metric with request_blocked:false not found ' +
                '-- rust helper must emit request_blocked:false on non-blocked requests (RFC-1012)'
        assert 'request_blocked:false' in wafReqNotBlocked.tags
    }

    /**
     * Verifies that _dd.appsec.rasp.duration (ms) and _dd.appsec.rasp.duration_ext (µs) are
     * emitted as span metrics and that appsec.rasp.duration / appsec.rasp.duration_ext are
     * emitted as DDSketch distributions (both µs). Cross-checks consistency:
     *  - rasp.duration: span metric ms × 1000 → µs must fall inside a populated bin
     *  - rasp.duration_ext: span metric µs falls directly inside a populated bin (no conversion)
     *
     * This test only applies to the Rust helper (distributions not implemented elsewhere).
     */
    @Test
    @Order(11)
    void 'rasp duration span metrics and distributions are consistent'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'appsec.rasp.duration distributions are only implemented on the Rust helper')

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert requestSup.get() != null

        // This span's RASP duration metrics will be cross-checked against the distributions.
        def trace = CONTAINER.traceFromRequest(
                '/multiple_rasp.php?path=../somefile&other=../otherfile&domain=169.254.169.254') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }

        TelemetryHelpers.DistributionMetric raspDuration
        TelemetryHelpers.DistributionMetric raspDurationExt

        TelemetryHelpers.waitForDistributions(CONTAINER, 30) { List<TelemetryHelpers.GenerateDistributions> messages ->
            def allSeries = messages.collectMany { it.series }
            raspDuration = raspDuration ?: allSeries.find { it.name == 'rasp.duration' }
            raspDurationExt = raspDurationExt ?: allSeries.find { it.name == 'rasp.duration_ext' }
            raspDuration != null && raspDurationExt != null
        }

        assert raspDuration != null : 'rasp.duration distribution metric not found'
        assert raspDuration.namespace == 'appsec'
        assert raspDuration.tags.find { it.startsWith('waf_version:') } != null
        assert raspDuration.tags.find { it.startsWith('event_rules_version:') } != null
        assert raspDuration.count >= 1.0

        assert raspDurationExt != null : 'rasp.duration_ext distribution metric not found'
        assert raspDurationExt.namespace == 'appsec'
        assert raspDurationExt.tags.find { it.startsWith('waf_version:') } != null
        assert raspDurationExt.tags.find { it.startsWith('event_rules_version:') } != null
        assert raspDurationExt.count >= 1.0

        def span = trace[0]
        def raspDurationUs = span.metrics.'_dd.appsec.rasp.duration'
        assert raspDurationUs != null && raspDurationUs > 0.0d :
            '_dd.appsec.rasp.duration span metric must be > 0'
        def raspDurationExtUs = span.metrics.'_dd.appsec.rasp.duration_ext'
        assert raspDurationExtUs != null && raspDurationExtUs > 0.0d :
            '_dd.appsec.rasp.duration_ext span metric must be > 0'

        assert raspDurationExtUs >= raspDurationUs :
           '_dd.appsec.rasp.duration_ext should be >= .duration'

        // Both rasp.duration span metric and distribution are in µs.
        assert raspDuration.countForBinContaining(raspDurationUs) != null :
            "span metric value ${raspDurationUs} µs not found in any " +
            "rasp.duration distribution bin; distribution: ${raspDuration}"

        // Both the span metric and the rasp.duration_ext distribution are in µs.
        assert raspDurationExt.countForBinContaining(raspDurationExtUs) != null :
            "span metric value ${raspDurationExtUs} µs not found in any rasp.duration_ext " +
            "distribution bin; distribution: ${raspDurationExt}"
    }

    /**
     * RFC-1012: appsec.waf.requests must include the rate_limited boolean tag.
     * The rate limiter must only be consulted when the WAF triggered (waf_keep=true),
     * matching C++ semantics: `event.keep && limiter_.allow()`. Clean requests must
     * not consume a limiter slot.
     *
     * This test verifies:
     * 1. Clean requests do not consume the limiter slot.
     * 2. The first attack request gets rate_limited:false (slot was preserved).
     * 3. The second attack request gets rate_limited:true (slot now exhausted).
     *
     * Only applies to the Rust helper. Ordered last because it restarts php-fpm
     * with a different rate limit configuration.
     */
    @Test
    @Order(20)
    void 'waf requests rate_limited tag is emitted'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'rate_limited tag is only implemented on the Rust helper')

        // Restart php-fpm with a rate limit of 1 trace/sec.
        org.testcontainers.containers.Container.ExecResult res = CONTAINER.execInContainer(
                'bash', '-c',
                '''kill -9 `pgrep php-fpm`;
                   export DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=1;
                   export DD_APPSEC_TRACE_RATE_LIMIT=1;
                   php-fpm -y /etc/php-fpm.conf -c /etc/php/php-rc.ini''')
        assert res.exitCode == 0

        Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                        asm: [enabled: true]
                ]
        ])

        // First request: starts helper. May or may not be covered by appsec depending on RC timing.
        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert requestSup.get() != null

        // Several clean requests: these must NOT consume the rate-limiter slot.
        // With correct behavior (matching C++), the limiter is only consulted when
        // the WAF triggered, so clean requests leave the slot untouched.
        for (int i = 0; i < 3; i++) {
            CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }
        }

        // Send a burst of attack requests. With rate_limit=1, all requests within the
        // same second share a single slot: the first gets rate_limited:false and the
        // rest get rate_limited:true. Sending several increases the chance that at
        // least two land within the same second even under CI timing variance.
        HttpRequest attackReq = CONTAINER.buildReq('/hello.php')
                .header('User-Agent', 'Arachni/v1').GET().build()
        for (int i = 0; i < 5; i++) {
            CONTAINER.traceFromRequest(attackReq, ofString()) { HttpResponse<String> resp ->
                assert resp.body().size() > 0
            }
        }

        TelemetryHelpers.Metric wafReqRateLimited
        TelemetryHelpers.Metric wafReqNotRateLimited

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            // Both must be attack requests (rule_triggered:true) to verify the limiter
            // only fires for WAF-triggered requests, not clean ones.
            wafReqRateLimited = allSeries.find {
                it.name == 'waf.requests' &&
                        'rule_triggered:true' in it.tags &&
                        'rate_limited:true' in it.tags
            }
            wafReqNotRateLimited = allSeries.find {
                it.name == 'waf.requests' &&
                        'rule_triggered:true' in it.tags &&
                        'rate_limited:false' in it.tags
            }
            wafReqRateLimited != null && wafReqNotRateLimited != null
        }

        assert wafReqNotRateLimited != null
        assert wafReqNotRateLimited.namespace == 'appsec'
        assert wafReqNotRateLimited.type == 'count'
        assert 'rule_triggered:true' in wafReqNotRateLimited.tags
        assert 'rate_limited:false' in wafReqNotRateLimited.tags

        assert wafReqRateLimited != null
        assert wafReqRateLimited.namespace == 'appsec'
        assert wafReqRateLimited.type == 'count'
        assert wafReqRateLimited.points[0][1] >= 1.0
        assert 'rule_triggered:true' in wafReqRateLimited.tags
        assert 'rate_limited:true' in wafReqRateLimited.tags
    }

    /**
     * Verifies that appsec.rasp.rule.match carries the `block` tag with the correct value:
     *   - block:irrelevant  → RASP rule matched but `on_match` did not request a block
     *   - block:success     → RASP rule matched, block was requested and (per PHP semantics)
     *                         always succeeds; PHP cannot fail to block once it decides to.
     *
     * Cross-tracer spec (see dd-trace-go, dd-trace-py): the tag is always emitted on
     * rasp.rule.match. PHP never emits block:failure because the layer is assumed to
     * always succeed at terminating the script.
     *
     * Only applies to the Rust helper.
     */
    @Test
    @Order(12)
    void 'rasp rule match has block tag'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'block tag on rasp.rule.match is only implemented on the Rust helper')

        try {
            // Phase 1: non-blocking RASP rule match (recommended.json lfi/ssrf rules have
            // on_match: ["stack_trace"], so a match does not block). Expect block:irrelevant.
            Supplier<RemoteConfigRequest> requestSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                    'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                            asm: [enabled: true]
                    ]
            ])

            CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }
            assert requestSup.get() != null

            CONTAINER.traceFromRequest(
                    '/multiple_rasp.php?path=../somefile&other=../otherfile&domain=169.254.169.254') {
                HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
            }

            // Phase 2: override the LFI rule on_match to ["block"]. The first @fopen in
            // multiple_rasp.php hits an LFI match, the WAF returns block_request, and the
            // PHP layer terminates the request. Expect block:success on the rasp.rule.match
            // emitted for rule_type:lfi from that request.
            Supplier<RemoteConfigRequest> overrideSup = CONTAINER.applyRemoteConfig(RC_TARGET, [
                    'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                            asm: [enabled: true]
                    ],
                    'datadog/2/ASM/rasp_lfi_block_override/config': [
                            rules_override: [[
                                                     rules_target: [[rule_id: 'rasp-001-001']],
                                                     on_match: ['block']
                                             ]]
                    ]
            ])
            assert overrideSup.get() != null

            // Trigger the blocking LFI; status code is 403 because the rule now blocks.
            HttpRequest blockingReq = CONTAINER.buildReq(
                    '/multiple_rasp.php?path=../somefile&other=../otherfile&domain=169.254.169.254')
                    .GET().build()
            CONTAINER.traceFromRequest(blockingReq, ofString()) { HttpResponse<String> resp ->
                assert resp.statusCode() == 403
            }

            TelemetryHelpers.Metric lfiMatchIrrelevant
            TelemetryHelpers.Metric lfiMatchSuccess

            TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
                def allSeries = messages.collectMany { it.series }
                lfiMatchIrrelevant = lfiMatchIrrelevant ?: allSeries.find {
                    it.name == 'rasp.rule.match' &&
                            'rule_type:lfi' in it.tags &&
                            'block:irrelevant' in it.tags
                }
                lfiMatchSuccess = lfiMatchSuccess ?: allSeries.find {
                    it.name == 'rasp.rule.match' &&
                            'rule_type:lfi' in it.tags &&
                            'block:success' in it.tags
                }
                lfiMatchIrrelevant != null && lfiMatchSuccess != null
            }

            assert lfiMatchIrrelevant != null :
                    'rasp.rule.match metric with block:irrelevant not found — ' +
                    'helper must emit block:irrelevant when the matched RASP rule has no block action'
            assert lfiMatchIrrelevant.namespace == 'appsec'
            assert lfiMatchIrrelevant.type == 'count'
            assert lfiMatchIrrelevant.points[0][1] >= 1.0d

            assert lfiMatchSuccess != null :
                    'rasp.rule.match metric with block:success not found — ' +
                    'helper must emit block:success when the matched RASP rule triggered a block'
            assert lfiMatchSuccess.namespace == 'appsec'
            assert lfiMatchSuccess.type == 'count'
            assert lfiMatchSuccess.points[0][1] >= 1.0d
        } finally {
            // Drop the override so subsequent ordered tests in this class run with the
            // default (non-blocking) RASP ruleset, even if assertions above failed.
            CONTAINER.applyRemoteConfig(RC_TARGET, [
                    'datadog/2/ASM_FEATURES/asm_features_activation/config': [
                            asm: [enabled: true]
                    ],
                    'datadog/2/ASM/rasp_lfi_block_override/config': null
            ])
        }
    }

    @Test
    @Order(21)
    void 'waf config errors emitted with action init for bad init rules'() {
        CONTAINER.execInContainer('bash', '-c',
                'printf \'%s\' \'{"version":"2.2","metadata":{"rules_version":"9.9.9"},"rules":[{"id":"t1","name":"G","tags":{"type":"test","category":"test"},"conditions":[{"parameters":{"inputs":[{"address":"http.client_ip"}],"data":"blocked_ips"},"operator":"ip_match"}],"transformers":[],"on_match":["block"]},{"id":"bad-rule"}]}\' > /tmp/bad_rules.json'
        )

        def res = CONTAINER.execInContainer('bash', '-c',
                '''kill -9 `pgrep php-fpm`;
                   export DD_APPSEC_ENABLED=1;
                   export DD_APPSEC_RULES=/tmp/bad_rules.json;
                   export DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=1;
                   php-fpm -y /etc/php-fpm.conf -c /etc/php/php-rc.ini''')
        assert res.exitCode == 0

        CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        boolean useRust = System.getProperty('USE_HELPER_RUST') != null
        TelemetryHelpers.Metric configError = null
        TelemetryHelpers.Log bundledDiagLog = null

        // drainTelemetry is destructive: collect metrics and logs in a single loop
        def deadline = System.currentTimeSeconds() + 30
        def lastHttpReq = System.currentTimeSeconds() - 6
        while (System.currentTimeSeconds() < deadline) {
            if (System.currentTimeSeconds() - lastHttpReq > 5) {
                lastHttpReq = System.currentTimeSeconds()
                CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                    assert resp.statusCode() == 200
                }
            }
            def telData = CONTAINER.drainTelemetry(500)

            configError = configError ?:
                TelemetryHelpers.filterMessages(telData, TelemetryHelpers.GenerateMetrics)
                    .collectMany { it.series }
                    .find { it.name == 'waf.config_errors' && 'event_rules_version:9.9.9' in it.tags }

            if (useRust) {
                bundledDiagLog = bundledDiagLog ?:
                    TelemetryHelpers.filterMessages(telData, TelemetryHelpers.Logs)
                        .collectMany { it.logs }
                        .find { it.tags?.contains('log_type:rc::bundled_rules::diagnostic') &&
                                it.tags?.contains('appsec_config_key:rules') }
                if (configError != null && bundledDiagLog != null) break
            } else {
                if (configError != null) break
            }
        }

        assert configError != null
        assert configError.namespace == 'appsec'
        assert 'event_rules_version:9.9.9' in configError.tags
        assert 'config_key:rules' in configError.tags
        if (useRust) {
            assert 'action:init' in configError.tags

            assert bundledDiagLog != null : 'Expected diagnostic log for bundled rules init errors'
            assert bundledDiagLog.level == 'ERROR'
            assert bundledDiagLog.tags?.contains('rc_config_id:bundled_rules')
            assert bundledDiagLog.message == "{\"missing key 'conditions'\":[\"bad-rule\"]}"
        }
    }
}
