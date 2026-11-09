package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.PhpFpm
import com.datadog.appsec.php.integration.Php2ShellHelpers.CarrierReq
import com.datadog.appsec.php.integration.Php2ShellHelpers.StartupReq
import com.datadog.appsec.php.integration.Php2ShellHelpers.TraceLog
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.Php2ShellHelpers.CARRIER_PATH
import static com.datadog.appsec.php.integration.Php2ShellHelpers.HEADER_TAGS
import static com.datadog.appsec.php.integration.Php2ShellHelpers.METRIC_DEFERRED
import static com.datadog.appsec.php.integration.Php2ShellHelpers.ROOT_SPAN_NAME
import static com.datadog.appsec.php.integration.Php2ShellHelpers.STARTUP_MEMORY_LIMIT
import static com.datadog.appsec.php.integration.Php2ShellHelpers.STARTUP_POST_MAX_SIZE
import static com.datadog.appsec.php.integration.Php2ShellHelpers.assertCarrier
import static com.datadog.appsec.php.integration.Php2ShellHelpers.assertStartupSpan
import static com.datadog.appsec.php.integration.Php2ShellHelpers.finalizeTelemetry
import static com.datadog.appsec.php.integration.Php2ShellHelpers.removeStartupConf
import static com.datadog.appsec.php.integration.Php2ShellHelpers.sendCarrier
import static com.datadog.appsec.php.integration.Php2ShellHelpers.sendProbe
import static com.datadog.appsec.php.integration.Php2ShellHelpers.sendStartupReq
import static com.datadog.appsec.php.integration.Php2ShellHelpers.startupHeaders
import static com.datadog.appsec.php.integration.Php2ShellHelpers.startupSpanReq
import static com.datadog.appsec.php.integration.Php2ShellHelpers.metricSumsAtClose
import static com.datadog.appsec.php.integration.Php2ShellHelpers.uniqueService
import static com.datadog.appsec.php.integration.Php2ShellHelpers.writeStartupConf
import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.junit.jupiter.api.Assumptions.assumeTrue

/**
 * End-to-end coverage of the PHP2Shell mitigation (CVE-2026-109618) under
 * php-fpm: a request body that crosses memory_limit while being parsed ends
 * in a deferred fatal at a safe point, the hard limit is not reached for a
 * body within post_max_size, the two telemetry metrics reach the agent, and
 * the gate follows appsec's state from the pool environment and from remote
 * config.
 *
 * memory_limit and post_max_size are set with php_admin_value in the pool, so
 * they are in force before the body is parsed. The mitigation is forced on
 * through the pool env and the master's environment (the hooks are installed
 * from the master's configuration, see dd_p2s_policy_startup_may_hook), so the
 * runs do not depend on whether this image's PHP carries the upstream fix.
 *
 * Apache (not nginx): nginx's default client_max_body_size (~1 MiB) would 413
 * the large bodies before PHP parsed them.
 *
 * No trace assertions in a-d: the attacks end the worker mid-request (a fatal
 * in the startup POST parse makes php-fpm exit it with code 70, a hard limit
 * with 137), leaving partial traces. Plain HTTP calls are used for pool
 * recovery and to drive telemetry flushes.
 *
 * e_waf_activation leaves the mode at auto and lets the WAF decide: the
 * default ruleset's PHP2Shell rule (block), a remote-config ruleset with the
 * rule's action in monitor mode, then one without the rule (off). Its
 * crossings are runtime allocations, whose fatals end only the request, so
 * it checks the span tags and the trace keep. It needs a vulnerable runtime
 * and is skipped on an image whose PHP carries the upstream fix.
 *
 * f to i cover the startup span: a request whose startup fails in the startup
 * window has no root span, so the next traced request sends its span as a
 * trace of its own (see Php2ShellHelpers). They come from
 * Php2ShellStartupSpanCases, which Php2ShellStartupSpanModTests also runs on
 * mod_php. Each runs with a single worker that is never recycled (see
 * withStartupSpanPool); a failed worker exits, and its replacement runs the
 * next request. j_startup_span_two_pools adds a second pool,
 * under another user, and a third, under the first pool's user: a startup
 * fatal in one pool is sent by that pool's next request and not by the
 * others', whose workers do not even map the slot. k covers a carrier that
 * runs no PHP code, and l standalone AppSec.
 *
 * The methods run in name order. a_bounded is the first to emit a metric, and
 * b_hard waits for a metric only it emits, so no case needs to drain series
 * left by an earlier one; d and e emit nothing that a later case waits for.
 * c_pool_env_disabled and g_startup_span_burst check the absence or the exact
 * count of a metric under a service of their own, whose telemetry they end
 * (see Php2ShellHelpers.metricSumsAtClose), so no earlier case's late points
 * can reach them and no heartbeat needs to pass.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
@TestMethodOrder(MethodOrderer.MethodName)
class Php2ShellMitigationTests implements Php2ShellStartupSpanCases {
    static boolean disabled = !(phpVersion in ['7.0', '7.4', '8.3', '8.5'])

    // The 1 s remote config poll interval is set for the whole container, not
    // only for d_remcfg_disabled's php-fpm: the sidecar keeps polling with the
    // interval of the first php-fpm that subscribed (the earlier cases'), and
    // at the default 5 s, applying a config and seeing it acknowledged (two
    // polls) does not fit applyRemoteConfig's 5 s wait. The telemetry
    // heartbeat of 1 s (the minimum; the sidecar also aggregates metrics at
    // that interval) is how long a metric point waits in the sidecar before
    // it is sent.
    @Container
    public static final AppSecContainer CONTAINER = new AppSecContainer(
            workVolume: this.name,
            baseTag: 'apache2-fpm-php',
            phpVersion: phpVersion,
            phpVariant: variant,
            www: 'base',
    ).withEnv('DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS', '1')
            .withEnv('DD_TELEMETRY_HEARTBEAT_INTERVAL', '1')

    // php.ini without datadog.appsec.enabled (an explicit INI value wins over
    // the environment) and without datadog.appsec.rules (so remote config
    // drives the rules), as in RemoteConfigTests.
    private static final String NO_APPSEC_INI = '/etc/php/php-p2s.ini'
    private static final String PHP_ERROR_LOG = '/tmp/logs/php_error.log'
    private static final Target RC_TARGET = new Target('appsec_int_tests', 'integration', '')
    private static final String ASM_FEATURES = 'datadog/2/ASM_FEATURES/asm_features_activation/config'
    private static final String SCANNER_UA = 'dd-test-scanner-log-block'
    private static final long MIB = 1024L * 1024L
    // The calibrated single-field shape of cases c and d.
    private static final long FIELD_LEN = 5_000_000L
    // a_bounded: a field of 3 x memory_limit, so the unfinished name alone,
    // buffered whole before it is registered, needs more than the 2 x
    // memory_limit floor of the hard limit.
    private static final long BIG_LIMIT = 8L * MIB
    private static final long BIG_FIELD_LEN = 3L * BIG_LIMIT
    // b_hard: with post_max_size=0 the hard limit is the cap, S + max(1 GiB,
    // 2S): 1472 MiB here. A single field name cannot get there within
    // Apache's default LimitRequestBody (1 GiB): once usage passes S the
    // wrapper drops the variable, so the parse holds about one copy of the
    // body. Instead, one value of V '<' bytes (2V below S, so the wrapper
    // lets it through) is expanded by filter.default=special_chars ('<' to
    // '&#60;') inside ext/filter: the buffered body, the value and
    // ext/filter's raw and filtered copies (4V) plus the 5V output reach
    // 9V = 1656 MiB.
    private static final long HARD_LIMIT = 448L * MIB
    private static final long HARD_VALUE_LEN = 184L * MIB
    private static final String METRIC_HARD_EXIT = 'appsec.php2shell.hard_limit_exit'
    private static final String DEFERRED_SUFFIX = 'Datadog AppSec PHP2Shell mitigation deferred'
    private static final String NO_RULES_INI = '/etc/php/php-p2s-norules.ini'
    private static final String ASM_DD = 'datadog/2/ASM_DD/php2shell_rules/config'
    // e_waf_activation: one runtime allocation of WAF_ALLOC under WAF_LIMIT,
    // between the soft and the hard limit when armed.
    private static final long WAF_LIMIT = 32L * MIB
    private static final long WAF_ALLOC = 56L * MIB
    // j_startup_span_two_pools: the second pool, and the layout of the
    // startup span region (a directory of one page, then the slots).
    private static final String POOL_A = 'www'
    private static final String POOL_B = 'p2sb'
    private static final int POOL_B_PORT = 9001
    private static final String POOL_C = 'p2sc'
    private static final int POOL_C_PORT = 9002
    private static final long SLOT_COUNT = 64
    private static final long SLOT_SIZE = 64L * 1024L

    @Test
    void 'a_bounded: a field beyond 2 x memory_limit within post_max_size is deferred and reported'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        calibrateBaseBytes(fpm)
        // post_max_size=32M bounds the input, which raises the hard limit well
        // above what parsing the 24 MiB name costs (goal E). The overflow past
        // memory_limit becomes a deferred fatal (goal D: its metric).
        log.info("bounded: memory_limit=${BIG_LIMIT.intdiv(MIB)}M post_max_size=32M field=${BIG_FIELD_LEN}")
        withPool(fpm, memoryLimit: "${BIG_LIMIT.intdiv(MIB)}M", postMaxSize: '32M') {
            int status = postAttack(BIG_FIELD_LEN)
            // The deferred fatal is raised from the startup POST parse, so
            // php_request_startup() fails: php-fpm ends the request with no
            // output (Apache answers 200 with an empty body) and the worker
            // exits with FPM_EXIT_SOFTWARE (70). A hard _exit(137) instead
            // drops the connection mid-request (503 or -1).
            assert status == 200 : "expected the deferred fatal (200, empty body); got ${status}"
            assert httpGet('/hello.php') == 200 // pool recovers
            List<Metric> series = waitForMetricSeries(METRIC_DEFERRED)
            findCountMetric(series, METRIC_DEFERRED)
            assert !series.any { it.name == METRIC_HARD_EXIT } : "deferred OOM must not hard-exit; got ${series*.name as Set}"
        }
    }

    @Test
    void 'b_hard: a hard-limit exit reaches telemetry before _exit and the pool recovers'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        // post_max_size=0 puts no bound on the input, so the hard limit is the
        // cap. ext/filter's expansion of the value crosses it while the value
        // is filtered, and the mitigation flushes its metric, then _exit(137)s.
        log.info("hard: memory_limit=${HARD_LIMIT.intdiv(MIB)}M post_max_size=0 value=${HARD_VALUE_LEN}")
        withPool(fpm, memoryLimit: "${HARD_LIMIT.intdiv(MIB)}M", postMaxSize: '0',
                poolLines: ['php_admin_value[filter.default] = special_chars']) {
            int status = postReq(streamedReq('f='.bytes, (byte) 0x3C /* '<' */, HARD_VALUE_LEN, new byte[0]))
            // A hard exit drops the connection mid-request (503 or -1); 200
            // would be a deferred fatal or a completed request.
            assert status != 200 : "expected the hard exit; got ${status}"
            // FPM respawns the worker; a normal request works again.
            assert httpGet('/hello.php') == 200
            findCountMetric(waitForMetricSeries(METRIC_HARD_EXIT), METRIC_HARD_EXIT)
        }
    }

    @Test
    void 'c_pool_env_disabled: DD_APPSEC_ENABLED=0 in the pool env closes the gate'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        long peak = calibratePeakBytes(fpm)
        int limitMib = Math.max(4L, Math.round(peak * 0.7d / MIB)) as int
        // The pool env is applied in the worker after MINIT; the gate reads it
        // before the first startup parse. A single field is used: the engine's
        // own out-of-memory bailout while it is accumulated leaves no
        // half-built array. php-fpm's INI parser turns an unquoted false into
        // an empty value, which it refuses for env[], hence 0. The pool has a
        // service of its own, so the telemetry checked below is only this
        // pool's, and ending it sends everything the sidecar holds for it.
        String service = uniqueService('p2s-c')
        withPool(fpm, memoryLimit: "${limitMib}M", postMaxSize: '32M', ini: noAppsecIni(),
                poolLines: ['env[DD_APPSEC_ENABLED] = 0', "env[DD_SERVICE] = ${service}".toString()]) {
            long logOffset = errorLogSize()
            postAttack(FIELD_LEN)
            assert httpGet('/hello.php') == 200 // pool recovers
            assertNativeFatal(errorLogSince(logOffset), limitMib)
            finalizeTelemetry(CONTAINER)
            List<Metric> series = TelemetryHelpers.metricSeriesUntilClosed(CONTAINER, service)
            assert !series.any { it.name.startsWith('appsec.php2shell.') } :
                    "no php2shell metric expected; got ${series*.name as Set}"
        }
    }

    @Test
    void 'd_remcfg_disabled: the gate follows appsec activation from remote config'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        long peak = calibratePeakBytes(fpm)
        int limitMib = Math.max(4L, Math.round(peak * 0.7d / MIB)) as int
        // One static worker, never recycled: the "mitigation allowed" flag is
        // per worker process. A worker whose startup fails exits, and its
        // replacement starts with the flag true and appsec unresolved, so each
        // attack follows a non-attack request in the same worker that synced
        // with the helper. DD_APPSEC_ENABLED is not set: enablement through
        // remote config needs the default.
        withPool(fpm, memoryLimit: "${limitMib}M", postMaxSize: '32M', ini: noAppsecIni(),
                poolValues: ['pm.max_requests': '0']) {
            boolean passed = false
            try {
                // As RemoteConfigTests: a request subscribes the restarted
                // php-fpm, then wait for a poll with no config applied yet.
                assert httpGet('/hello.php') == 200
                CONTAINER.waitForRCVersion(RC_TARGET, 0, 12_000)
                // Remote config works and activates appsec in this worker.
                applyAsmFeatures(true)
                waitForScannerStatus(403)
                // 1. Deactivated: the sync reports appsec inactive, which
                // clears the flag; the attack gets the engine's own error.
                applyAsmFeatures(false)
                waitForScannerStatus(200)
                long logOffset = errorLogSize()
                postAttack(FIELD_LEN)
                assertNativeFatal(errorLogSince(logOffset), limitMib)
                // 2. Activated again, synced on the replacement worker: the
                // attack ends in the deferred fatal.
                applyAsmFeatures(true)
                waitForScannerStatus(403)
                logOffset = errorLogSize()
                assert postAttack(FIELD_LEN) == 200
                String log2 = errorLogSince(logOffset)
                assert log2.contains("Allowed memory size of ${limitMib * MIB} bytes exhausted (${DEFERRED_SUFFIX}") :
                        "expected the deferred fatal in the error log; got: ${log2.take(2000)}"
                assert httpGet('/hello.php') == 200
                passed = true
            } finally {
                // A failed cleanup must not hide the failure of the body.
                try {
                    CONTAINER.applyRemoteConfig(RC_TARGET, [:]).get()
                } catch (Throwable t) {
                    log.warn("remote config cleanup failed: ${t.message}")
                    if (passed) {
                        throw t
                    }
                }
            }
        }
    }

    @Test
    void 'e_waf_activation: under auto, the PHP2Shell rule of the ruleset decides block, monitor or off'() {
        // The attack crosses memory_limit at runtime, which only the request
        // level (PHP 7.1+, a VM interrupt) defers.
        assumeTrue(phpVersion != '7.0', 'the request level needs PHP 7.1')
        PhpFpm fpm = new PhpFpm(CONTAINER)
        // Mode auto, the real helper and its default ruleset (the php.ini
        // without datadog.appsec.rules, so that remote config can replace it).
        // One static worker, never recycled: the decision is per worker
        // process, and the runtime fatals below do not end the worker.
        withPool(fpm, memoryLimit: "${WAF_LIMIT.intdiv(MIB)}M", postMaxSize: '8M', mode: 'auto',
                ini: noRulesIni(), poolValues: ['pm.max_requests': '0']) {
            boolean passed = false
            try {
                // A request subscribes the restarted php-fpm; then an empty
                // config, acknowledged, is the baseline whatever version an
                // earlier case left.
                assert httpGet('/hello.php') == 200
                CONTAINER.applyRemoteConfig(RC_TARGET, [:]).get()
                Map<String, String> info = p2sInfo()
                log.info("PHP2Shell MINFO rows: ${info}")
                // Under auto, only a vulnerable runtime sends the address the
                // rule matches on. The runtime override
                // (DD_APPSEC_TESTING_PHP2SHELL_RUNTIME) needs
                // datadog.appsec.testing, which turns off the automatic
                // request_init and the helper, so it cannot be used here.
                assumeTrue(info['PHP2Shell patched runtime'] == 'No',
                        "this image's PHP carries the upstream fix; got ${info}")
                assert info['PHP2Shell mitigation mode'] == 'auto'

                // 1. Default ruleset, action mode block: armed. The rule's
                // match is not an appsec event, on a plain request either.
                waitForDecision('block')
                Trace plain = CONTAINER.traceFromRequest('/hello.php', null, true)
                assertNoAppsecEvent(plain)
                assert plain.first().metrics._sampling_priority_v1 != 2.0d
                def (Trace t1, String log1) = wafAttack()
                assert log1.contains("Allowed memory size of ${WAF_LIMIT} bytes exhausted (${DEFERRED_SUFFIX}") :
                        "expected the deferred fatal; got: ${log1.take(2000)}"
                assertP2sTags(t1, [deferred: 'runtime'])
                assert t1.first().metrics._sampling_priority_v1 == 2.0d

                // 2. A remote-config ruleset whose action has mode monitor: a
                // new ruleset generation, so the helper's cached decision is
                // not reused. Disarmed, the engine's own fatal, seen and
                // reported.
                CONTAINER.applyRemoteConfig(RC_TARGET, [(ASM_DD): wafRuleset('monitor')]).get()
                waitForDecision('monitor')
                def (Trace t2, String log2) = wafAttack()
                assertWafNativeFatal(log2)
                assertP2sTags(t2, [memory_error: 'memory_limit', phase: 'runtime'])
                assert t2.first().metrics._sampling_priority_v1 == 2.0d

                // 3. A remote-config ruleset without the rule: the WAF ran
                // and nothing matched, so the mitigation is off.
                CONTAINER.applyRemoteConfig(RC_TARGET, [(ASM_DD): wafRuleset(null)]).get()
                waitForDecision('none')
                def (Trace t3, String log3) = wafAttack()
                assertWafNativeFatal(log3)
                assertP2sTags(t3, [:])
                assert t3.first().metrics._sampling_priority_v1 != 2.0d
                passed = true
            } finally {
                // A failed cleanup must not hide the failure of the body.
                try {
                    CONTAINER.applyRemoteConfig(RC_TARGET, [:]).get()
                } catch (Throwable t) {
                    log.warn("remote config cleanup failed: ${t.message}")
                    if (passed) {
                        throw t
                    }
                }
            }
        }
    }

    @Test
    void 'j_startup_span_two_pools: a startup fatal in one pool is sent by that pool only'() {
        // A second pool, under another user, and a third, under the main
        // pool's user, which only the pool name tells apart from it. Each is
        // behind a path prefix of its own (its name).
        PhpFpm fpm = new PhpFpm(CONTAINER)
        writeExtraPool(fpm, POOL_B, 'nobody', 'nogroup', POOL_B_PORT)
        writeExtraPool(fpm, POOL_C, 'www-data', 'www-data', POOL_C_PORT)
        // The server-level ProxyPassMatch comes before the virtual host's,
        // which would also match these paths.
        List<String> apacheLines = [[POOL_B, POOL_B_PORT], [POOL_C, POOL_C_PORT]].collect { pool, port ->
            "ProxyPassMatch \"^/${pool}/(.*\\.php(/.*)?)\$\" \"fcgi://127.0.0.1:${port}/var/www/public/\$1\"".toString()
        }
        try {
            withStartupSpanPool(apacheLines) {
                String carrierB = "/${POOL_B}${CARRIER_PATH}".toString()
                String carrierC = "/${POOL_C}${CARRIER_PATH}".toString()
                // Pools B and C answer, each from a process of its own.
                Span processA = sendProbe(CONTAINER)
                Set<Object> pids = [processA.metrics.process_id] as Set
                [carrierB, carrierC].each { path ->
                    CarrierReq probe = sendCarrier(CONTAINER, [:], path)
                    Span process = CONTAINER.nextCapturedTraces([probe.traceId])[probe.traceId].rootSpan
                    assert pids.add(process.metrics.process_id) : "${path} ran in the process of another pool"
                }

                TraceLog received = new TraceLog(CONTAINER)
                long t0 = System.currentTimeMillis() * 1_000_000L
                StartupReq failed = startupSpanReq(CONTAINER, startupHeaders())
                assert sendStartupReq(CONTAINER, failed) == 200
                // Neither pool B's nor pool C's next request takes the event
                // of pool A. A span one of them took would travel in the same
                // payload as its own trace, so it has arrived once that trace
                // has.
                [carrierB, carrierC].each { path ->
                    CarrierReq other = sendCarrier(CONTAINER, [:], path)
                    assertCarrier(received.next([other.traceId])[other.traceId], other, path: path)
                }
                received.assertNotSent([failed.traceId])
                // Pool A's next request does.
                CarrierReq carrier = sendCarrier(CONTAINER)
                Map<BigInteger, Trace> traces = received.next([failed.traceId, carrier.traceId])
                assertStartupSpan(traces[failed.traceId], failed,
                        t0: t0, carrier: traces[carrier.traceId], process: processA, sameProcess: false)
                assertCarrier(traces[carrier.traceId], carrier)

                // Each worker maps only its own slot of the region the master
                // mapped before forking them: the slots of other pools are not
                // reachable from it.
                long page = CONTAINER.execInContainer('getconf', 'PAGESIZE').stdout.trim() as long
                List<long[]> master = sharedAnonMappings(fpm.masterPid()).findAll {
                    it[1] - it[0] == page + SLOT_COUNT * SLOT_SIZE
                }
                assert master.size() == 1 : "expected one startup span region in the master; got ${master}"
                long[] region = master.first()
                List<Long> slots = [POOL_A, POOL_B, POOL_C].collect { workerSlot(fpm, it, region)[0] }
                assert slots.toSet().size() == 3 : "two pools share a slot: ${slots}"
            }
        } finally {
            fpm.removePool(POOL_B)
            fpm.removePool(POOL_C)
            fpm.restart()
        }
    }

    @Test
    void 'k_startup_span_no_php_carrier: a carrier that runs no PHP code still sends the span'() {
        withStartupSpanPool {
            TraceLog received = new TraceLog(CONTAINER)
            long t0 = System.currentTimeMillis() * 1_000_000L
            StartupReq failed = startupSpanReq(CONTAINER, startupHeaders())
            assert sendStartupReq(CONTAINER, failed) == 200
            // php-fpm answers a missing script with a 404 after the request
            // startup, without running any PHP code: the tracer does not send
            // this request's own trace, but must send the span it took.
            CarrierReq missing = sendCarrier(CONTAINER, [:], '/p2s_missing.php', 404)
            Map<BigInteger, Trace> traces = received.next([failed.traceId])
            assertStartupSpan(traces[failed.traceId], failed, t0: t0)
            // The slot is empty again: the next request sends only its own
            // trace, and nothing else arrives behind it.
            CarrierReq next = sendCarrier(CONTAINER)
            assertCarrier(received.next([next.traceId])[next.traceId], next)
            received.barrier()
            received.assertNotSent([missing.traceId, failed.traceId])
        }
    }

    @Test
    void 'l_startup_span_standalone: the span does not use up the allowance of standalone AppSec'() {
        // php-fpm is restarted with standalone AppSec, which gives the
        // limiter a new window: the first trace closed in each minute is
        // kept, and the others are rejected unless AppSec keeps them.
        withStartupSpanPool([], [DD_APM_TRACING_ENABLED: 'false']) {
            long t0 = System.currentTimeMillis() * 1_000_000L
            StartupReq failed = startupSpanReq(CONTAINER, startupHeaders())
            assert sendStartupReq(CONTAINER, failed) == 200
            CarrierReq carrier = sendCarrier(CONTAINER)
            Map<BigInteger, Trace> traces = CONTAINER.nextCapturedTraces([failed.traceId, carrier.traceId])
            assertStartupSpan(traces[failed.traceId], failed, t0: t0, carrier: traces[carrier.traceId])
            Span root = traces[carrier.traceId].rootSpan
            assert root.name == ROOT_SPAN_NAME
            assert root.metrics['_dd.apm.enabled'] == 0.0d
            // The carrier's own trace is the first one of the window that the
            // limiter saw, so it keeps its allowance: it is kept by the
            // default sampling, not by AppSec (which also keeps it when the
            // limiter rejects it, with another decision maker).
            assert root.metrics._sampling_priority_v1 == 1.0d
            assert root.meta['_dd.p.dm'] == '-0'
        }
    }

    @Override
    <T> T startupSpanCase(Closure<T> body) {
        withStartupSpanPool(body)
    }

    // php-fpm ends a failed request with no output, as the worker exits;
    // Apache answers 200 with an empty body.
    @Override
    void sendFailed(StartupReq sr) {
        int status = sendStartupReq(CONTAINER, sr)
        assert status == 200 : "expected the failed request startup (200); got ${status}"
    }

    // Writes an extra pool `name` under `user`, listening on `port`: like the
    // main pool, a single worker under the startup span limits.
    private static void writeExtraPool(PhpFpm fpm, String name, String user, String group, int port) {
        String conf = """[${name}]
user = ${user}
group = ${group}
listen = 127.0.0.1:${port}
listen.allowed_clients = 127.0.0.1
pm = static
pm.max_children = 1
pm.max_requests = 0
clear_env = no
security.limit_extensions = .php
php_admin_value[memory_limit] = ${STARTUP_MEMORY_LIMIT}
php_admin_value[post_max_size] = ${STARTUP_POST_MAX_SIZE}
env[DD_APPSEC_PHP2SHELL_MITIGATION] = on
""".toString()
        fpm.addPool(name, conf)
    }

    // The single mapping a worker of `pool` has inside the master's startup
    // span `region` ([start, end)), which must be one slot.
    private static long[] workerSlot(PhpFpm fpm, String pool, long[] region) {
        String pid = fpm.workerPid(pool)
        List<long[]> inRegion = sharedAnonMappings(pid).findAll { it[0] < region[1] && it[1] > region[0] }
        assert inRegion.size() == 1 : "pool ${pool} worker ${pid} maps ${inRegion.collect { it as List }} of ${region as List}"
        long[] slot = inRegion.first()
        log.info("pool ${pool} worker ${pid} maps ${Long.toHexString(slot[0])}-${Long.toHexString(slot[1])} " +
                "of the region ${Long.toHexString(region[0])}-${Long.toHexString(region[1])}")
        assert slot[0] >= region[0] && slot[1] <= region[1]
        assert slot[1] - slot[0] == SLOT_SIZE
        slot
    }

    // The shared anonymous mappings ([start, end)) of process `pid`, as
    // listed in its /proc/<pid>/maps: the kernel shows them as /dev/zero.
    private static List<long[]> sharedAnonMappings(String pid) {
        def res = CONTAINER.execInContainer('cat', "/proc/${pid}/maps".toString())
        assert res.exitCode == 0 : res.stderr
        res.stdout.readLines().findAll { it.contains(' rw-s ') && it.contains('/dev/zero') }.collect {
            String[] range = it.split(' ')[0].split('-')
            [Long.parseUnsignedLong(range[0], 16), Long.parseUnsignedLong(range[1], 16)] as long[]
        }
    }

    // Run `body` for a startup span case: one static worker, never recycled,
    // under the startup span requests' memory_limit and post_max_size, with
    // the header tag of assertStartupSpan (the client IP is left off for the
    // tracer: the startup span carries it anyway), and Apache mapping the
    // per-request configuration headers, plus `apacheLines`, with `env` for
    // php-fpm. php-fpm is restarted, which also gives the case a new, empty
    // slot.
    private static <T> T withStartupSpanPool(List<String> apacheLines = [], Map<String, String> env = [:],
                                            Closure<T> body) {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        writeStartupConf(CONTAINER, apacheLines)
        try {
            CONTAINER.restartApache()
            withPool(fpm, memoryLimit: STARTUP_MEMORY_LIMIT, postMaxSize: STARTUP_POST_MAX_SIZE,
                    poolValues: ['pm.max_requests': '0'],
                    env: [DD_TRACE_HEADER_TAGS: HEADER_TAGS] + env) {
                CONTAINER.clearTraces()
                body()
            }
        } finally {
            removeStartupConf(CONTAINER)
            CONTAINER.restartApache()
        }
    }

    // Run `body` with the pool pinned to one static worker, the given
    // memory_limit and post_max_size (php_admin_value, appended: the pool
    // file has no such lines to rewrite), the mitigation mode `mode`
    // (default on) through the pool env, plus the appended `poolLines` and
    // the rewritten `poolValues`. php-fpm is restarted with `ini` and `env`;
    // PhpFpm.withPool restores the pool file and the default php.ini after
    // the block.
    private static <T> T withPool(Map opts, PhpFpm fpm, Closure<T> body) {
        String mode = (opts.mode ?: 'on') as String
        // The pool is already pm = static.
        fpm.withPool(values: ['pm.max_children': '1'] + ((opts.poolValues ?: [:]) as Map),
                lines: [
                        "php_admin_value[memory_limit] = ${opts.memoryLimit}".toString(),
                        "php_admin_value[post_max_size] = ${opts.postMaxSize}".toString(),
                        "env[DD_APPSEC_PHP2SHELL_MITIGATION] = ${mode}".toString(),
                ] + ((opts.poolLines ?: []) as List<String>),
                // The mode also goes to the master: the hooks are installed
                // (or not, under auto on a patched runtime) from its
                // environment.
                env: [DD_APPSEC_PHP2SHELL_MITIGATION: mode] + ((opts.env ?: [:]) as Map<String, String>),
                ini: opts.ini, body)
    }

    private static String noAppsecIni() {
        def res = CONTAINER.execInContainer('bash', '-c',
                "sed -e '/appsec.enabled/d' -e '/appsec.rules=/d' ${PhpFpm.DEFAULT_INI} > ${NO_APPSEC_INI}".toString())
        assert res.exitCode == 0 : res.stderr
        NO_APPSEC_INI
    }

    // php.ini without datadog.appsec.rules: the helper's default ruleset
    // applies, and remote config (ASM_DD) can replace it.
    private static String noRulesIni() {
        def res = CONTAINER.execInContainer('bash', '-c',
                "sed -e '/appsec.rules=/d' ${PhpFpm.DEFAULT_INI} > ${NO_RULES_INI}".toString())
        assert res.exitCode == 0 : res.stderr
        NO_RULES_INI
    }

    // The PHP2Shell rows of the worker's phpinfo(). The request's own
    // request_init has run by then, so the WAF decision row is that answer.
    private static Map<String, String> p2sInfo() {
        HttpResponse<String> resp = CONTAINER.httpClient.send(CONTAINER.buildReq('/phpinfo.php').GET().build(), ofString())
        assert resp.statusCode() == 200
        def m = (resp.body() =~ /<td class="e">(PHP2Shell[^<]*?)\s*<\/td><td class="v">([^<]*?)\s*<\/td>/)
        Map<String, String> rows = [:]
        while (m.find()) {
            rows[m.group(1)] = m.group(2)
        }
        assert rows : 'no PHP2Shell rows in phpinfo()'
        rows
    }

    // Until the worker's sticky decision is `expected`: the helper applies a
    // remote-config ruleset some time after the agent saw it acknowledged.
    private static void waitForDecision(String expected) {
        String decision = null
        long deadline = System.currentTimeMillis() + 15_000
        while (System.currentTimeMillis() < deadline) {
            decision = p2sInfo()['PHP2Shell WAF decision']
            if (decision == expected) {
                return
            }
            Thread.sleep(100)
        }
        assert decision == expected : "the PHP2Shell WAF decision stayed ${decision}, expected ${expected}"
    }

    // An ASM_DD ruleset with a scanner rule and, unless `mode` is null, the
    // PHP2Shell rule of the default ruleset with its action's mode set to
    // `mode`.
    private static Map wafRuleset(String mode) {
        List rules = [[
                id: 'ua0-600-12x',
                name: 'Arachni',
                tags: [type: 'security_scanner', category: 'attack_attempt'],
                conditions: [[
                        parameters: [inputs: [[address: 'server.request.headers.no_cookies', key_path: ['user-agent']]],
                                     regex: '^Arachni\\/v'],
                        operator: 'match_regex',
                ]],
                transformers: [],
        ]]
        Map ruleset = [version: '2.2', metadata: [rules_version: '1.99.0'], rules: rules]
        if (mode != null) {
            rules << [
                    id: 'dd-php2shell-001',
                    name: 'PHP2Shell mitigation for vulnerable PHP runtimes',
                    tags: [type: 'php2shell_mitigation', category: 'vulnerability_mitigation', module: 'waf'],
                    conditions: [[
                            parameters: [inputs: [[address: 'php.php2shell.vulnerable']], list: ['full', 'partial']],
                            operator: 'exact_match',
                    ]],
                    transformers: [],
                    on_match: ['php2shell_block'],
            ]
            ruleset.actions = [[id: 'php2shell_block', type: 'php2shell_mitigation', parameters: [mode: mode]]]
        }
        ruleset
    }

    // A runtime allocation past WAF_LIMIT; its trace and the error log lines
    // it wrote.
    private static List wafAttack() {
        long logOffset = errorLogSize()
        Trace trace = CONTAINER.traceFromRequest("/php2shell_runtime_oom.php?alloc=${WAF_ALLOC}", null, true)
        String errLog = errorLogSince(logOffset)
        log.info("waf attack: root meta=${trace.first().meta.findAll { it.key.contains('php2shell') }} " +
                "priority=${trace.first().metrics._sampling_priority_v1}")
        assertNoAppsecEvent(trace)
        [trace, errLog]
    }

    private static void assertWafNativeFatal(String log) {
        assert log.contains("Allowed memory size of ${WAF_LIMIT} bytes exhausted") :
                "expected the engine's out-of-memory error in the log; got: ${log.take(2000)}"
        assert !log.contains(DEFERRED_SUFFIX) : "the mitigation must not be armed; got: ${log.take(2000)}"
    }

    // The root span's _dd.appsec.php2shell.* tags are exactly `expected`
    // (names without the prefix).
    private static void assertP2sTags(Trace trace, Map<String, String> expected) {
        String prefix = '_dd.appsec.php2shell.'
        Map<String, String> got = trace.first().meta
                .findAll { it.key.startsWith(prefix) }
                .collectEntries { [(it.key.substring(prefix.length())): it.value] }
        assert got == expected
    }

    // The PHP2Shell rule's match is not an appsec event.
    private static void assertNoAppsecEvent(Trace trace) {
        Span root = trace.first()
        assert !root.meta.containsKey('appsec.event')
        assert !root.meta.containsKey('_dd.appsec.json')
        assert !root.meta_struct?.containsKey('appsec')
    }

    // A urlencoded body with one field name of `fieldLen` bytes.
    private static HttpRequest attackReq(long fieldLen) {
        streamedReq('f'.bytes, (byte) 0x5A /* 'Z' */, fieldLen, '[k]=1'.bytes)
    }

    // A urlencoded body of `head`, `count` copies of `fill`, then `tail`, to
    // the sink.
    private static HttpRequest streamedReq(byte[] head, byte fill, long count, byte[] tail) {
        CONTAINER.buildStreamedReq('/php2shell_sink.php', [:], head, fill, count, tail)
    }

    // Peak real memory the FIELD_LEN field needs when it does not overflow
    // (so the sink runs and reports it). Used to size memory_limit into the
    // deferred band.
    private static long calibratePeakBytes(PhpFpm fpm) {
        long peak = withPool(fpm, memoryLimit: '256M', postMaxSize: '32M') {
            HttpResponse<String> resp = CONTAINER.httpClient.send(attackReq(FIELD_LEN), ofString())
            String b = resp.body() ?: ''
            log.info("calibration status=${resp.statusCode()} body=[${b.trim()}]")
            def m = (b =~ /peak=(\d+)/)
            assert m.find() : "could not read peak from calibration body: [${b.trim()}]"
            m.group(1) as long
        }
        log.info("calibrated peak=${peak} bytes (~${(peak / MIB).round(1)} MiB) for field ${FIELD_LEN}")
        peak
    }

    // Peak real memory of a small request, an upper bound for the usage the
    // armed parse starts from. a_bounded needs it well under BIG_LIMIT, so
    // that the large field, not the base usage, decides the outcome.
    private static long calibrateBaseBytes(PhpFpm fpm) {
        long base = withPool(fpm, memoryLimit: '256M', postMaxSize: '32M') {
            HttpResponse<String> resp = CONTAINER.httpClient.send(
                    CONTAINER.buildReq('/php2shell_sink.php')
                            .header('Content-Type', 'application/x-www-form-urlencoded')
                            .POST(HttpRequest.BodyPublishers.ofString('a=1'))
                            .build(),
                    ofString())
            String b = resp.body() ?: ''
            log.info("base calibration status=${resp.statusCode()} body=[${b.trim()}]")
            def m = (b =~ /peak=(\d+)/)
            assert m.find() : "could not read peak from base calibration body: [${b.trim()}]"
            m.group(1) as long
        }
        log.info("calibrated base=${base} bytes (~${(base / MIB).round(1)} MiB)")
        assert base * 2 <= BIG_LIMIT : "base ${base} is not well under memory_limit ${BIG_LIMIT}"
        base
    }

    private static int postAttack(long fieldLen) {
        postReq(attackReq(fieldLen))
    }

    private static int postReq(HttpRequest req) {
        try {
            HttpResponse<String> resp = CONTAINER.httpClient.send(req, ofString())
            log.info("attack response status=${resp.statusCode()} body=${resp.body()?.take(120)}")
            return resp.statusCode()
        } catch (IOException e) {
            // A hard-limit _exit(137) tears the worker down mid-response; the
            // connection reset surfaces as an IOException.
            log.info("attack request connection error (expected for hard exit): ${e.message}")
            return -1
        }
    }

    private static int httpGet(String path, Map<String, String> headers = [:]) {
        def br = CONTAINER.buildReq(path).GET()
        headers.each { k, v -> br.header(k, v) }
        CONTAINER.httpClient.send(br.build(), ofString()).statusCode()
    }

    private static void applyAsmFeatures(boolean enabled) {
        CONTAINER.applyRemoteConfig(RC_TARGET, [(ASM_FEATURES): [asm: [enabled: enabled]]]).get()
    }

    // GET with the test scanner's user agent, which the default rules block
    // (403) while appsec is active, until it answers `expected`.
    private static void waitForScannerStatus(int expected) {
        int status = -1
        long deadline = System.currentTimeMillis() + 15_000
        while (System.currentTimeMillis() < deadline) {
            status = httpGet('/hello.php', ['User-Agent': SCANNER_UA])
            if (status == expected) {
                return
            }
            Thread.sleep(100)
        }
        assert status == expected : "scanner request kept answering ${status}, expected ${expected}"
    }

    private static long errorLogSize() {
        def res = CONTAINER.execInContainer('bash', '-c', "stat -c %s ${PHP_ERROR_LOG} 2>/dev/null || echo 0".toString())
        res.stdout.trim() as long
    }

    // The worker appends to the error log itself (error_log is a file), before
    // php-fpm ends the request, so the lines of a request that was answered
    // are there.
    private static String errorLogSince(long offset) {
        CONTAINER.execInContainer('bash', '-c', "tail -c +${offset + 1} ${PHP_ERROR_LOG}".toString()).stdout
    }

    private static void assertNativeFatal(String log, int limitMib) {
        String msg = "Allowed memory size of ${limitMib * MIB} bytes exhausted"
        assert log.contains(msg) : "expected the engine's out-of-memory error in the log; got: ${log.take(2000)}"
        assert !log.contains(DEFERRED_SUFFIX) : "the mitigation must be off; got: ${log.take(2000)}"
    }

    // Collect metric series until one named `name` arrives (or 20 s pass);
    // returns them all.
    private static List<Metric> waitForMetricSeries(String name) {
        List<Metric> series = []
        long deadline = System.currentTimeMillis() + 20_000
        while (!series.any { it.name == name } && System.currentTimeMillis() < deadline) {
            assert httpGet('/hello.php') == 200 // keep the worker busy so telemetry flushes
            series.addAll(TelemetryHelpers.drainMetricSeries(CONTAINER, 'appsec_int_tests'))
        }
        series
    }

    private static Metric findCountMetric(List<Metric> series, String name) {
        Metric m = series.find { it.name == name }
        assert m != null : "metric ${name} not received; got ${series*.name as Set}"
        assert m.namespace == 'appsec'
        assert m.type == 'count'
        assert m.points[0][1] >= 1.0
        assert 'mode:on' in m.tags
        return m
    }
}
