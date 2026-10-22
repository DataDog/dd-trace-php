package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.PhpFpm
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

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
 * through the pool env, so the runs do not depend on whether this image's PHP
 * carries the upstream fix.
 *
 * Apache (not nginx): nginx's default client_max_body_size (~1 MiB) would 413
 * the large bodies before PHP parsed them.
 *
 * No trace assertions: the attacks end the worker mid-request (a fatal in the
 * startup POST parse makes php-fpm exit it with code 70, a hard limit with
 * 137), leaving partial traces. Plain HTTP calls are used for pool recovery
 * and to drive telemetry flushes.
 *
 * The methods run in name order. a_bounded is the first to emit a metric, and
 * b_hard waits for a metric only it emits, so no case needs to drain series
 * left by an earlier one; c and d emit nothing that a later case waits for.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
@TestMethodOrder(MethodOrderer.MethodName)
class Php2ShellMitigationTests {
    static boolean disabled = !(phpVersion in ['7.0', '7.4', '8.3', '8.5'])

    // The 1 s remote config poll interval is set for the whole container, not
    // only for d_remcfg_disabled's php-fpm: the sidecar keeps polling with the
    // interval of the first php-fpm that subscribed (the earlier cases'), and
    // at the default 5 s, applying a config and seeing it acknowledged (two
    // polls) does not fit applyRemoteConfig's 5 s wait.
    @Container
    public static final AppSecContainer CONTAINER = new AppSecContainer(
            workVolume: this.name,
            baseTag: 'apache2-fpm-php',
            phpVersion: phpVersion,
            phpVariant: variant,
            www: 'base',
    ).withEnv('DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS', '1')

    private static final String POOL = '/etc/php-fpm.d/www.conf'
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
    private static final String METRIC_DEFERRED = 'appsec.php2shell.soft_limit_deferred'
    private static final String METRIC_HARD_EXIT = 'appsec.php2shell.hard_limit_exit'
    private static final String DEFERRED_SUFFIX = 'Datadog AppSec PHP2Shell mitigation deferred'

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
        // an empty value, which it refuses for env[], hence 0.
        withPool(fpm, memoryLimit: "${limitMib}M", postMaxSize: '32M', ini: noAppsecIni(),
                poolLines: ['env[DD_APPSEC_ENABLED] = 0']) {
            long logOffset = errorLogSize()
            postAttack(FIELD_LEN)
            assert httpGet('/hello.php') == 200 // pool recovers
            assertNativeFatal(errorLogSince(logOffset), limitMib)
            List<Metric> series = collectMetricSeries(3)
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

    // Run `body` with the pool pinned to one static worker, the given
    // memory_limit and post_max_size (php_admin_value: setPoolValue cannot add
    // a bracketed key), the mitigation forced on through the pool env, plus
    // the appended `poolLines` and the rewritten `poolValues`. php-fpm is
    // restarted with `ini` and `env`. The pool file is
    // snapshotted and restored around the block, and php-fpm restarted with
    // the default php.ini.
    private static <T> T withPool(Map opts, PhpFpm fpm, Closure<T> body) {
        List<String> lines = [
                "php_admin_value[memory_limit] = ${opts.memoryLimit}".toString(),
                "php_admin_value[post_max_size] = ${opts.postMaxSize}".toString(),
                'env[DD_APPSEC_PHP2SHELL_MITIGATION] = on',
        ] + (opts.poolLines ?: [])
        CONTAINER.execInContainer('cp', POOL, "${POOL}.p2s".toString())
        try {
            // The pool is already pm = static.
            fpm.setPoolValue('pm.max_children', '1')
            (opts.poolValues ?: [:]).each { k, v -> fpm.setPoolValue(k as String, v as String) }
            CONTAINER.execInContainer('bash', '-c',
                    "cat >> ${POOL} <<'EOF'\n${lines.join('\n')}\nEOF".toString())
            fpm.restart((opts.env ?: [:]) as Map<String, String>, (opts.ini ?: PhpFpm.DEFAULT_INI) as String)
            return body()
        } finally {
            CONTAINER.execInContainer('bash', '-c', "mv -f ${POOL}.p2s ${POOL}".toString())
            fpm.restart()
        }
    }

    private static String noAppsecIni() {
        def res = CONTAINER.execInContainer('bash', '-c',
                "sed -e '/appsec.enabled/d' -e '/appsec.rules=/d' ${PhpFpm.DEFAULT_INI} > ${NO_APPSEC_INI}".toString())
        assert res.exitCode == 0 : res.stderr
        NO_APPSEC_INI
    }

    // A urlencoded body with one field name of `fieldLen` bytes.
    private static HttpRequest attackReq(long fieldLen) {
        streamedReq('f'.bytes, (byte) 0x5A /* 'Z' */, fieldLen, '[k]=1'.bytes)
    }

    // A urlencoded body of `head`, `count` copies of `fill`, then `tail`,
    // streamed with a known length so that large bodies need no JVM heap.
    private static HttpRequest streamedReq(byte[] head, byte fill, long count, byte[] tail) {
        long length = head.length + count + tail.length
        def publisher = HttpRequest.BodyPublishers.ofInputStream {
            new SequenceInputStream(Collections.enumeration([
                    new ByteArrayInputStream(head),
                    new RepeatedByteInputStream(fill, count),
                    new ByteArrayInputStream(tail),
            ]))
        }
        CONTAINER.buildReq('/php2shell_sink.php')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.fromPublisher(publisher, length))
                .build()
    }

    private static class RepeatedByteInputStream extends InputStream {
        private final byte value
        private long remaining

        RepeatedByteInputStream(byte value, long count) {
            this.value = value
            this.remaining = count
        }

        @Override
        int read() {
            if (remaining <= 0) {
                return -1
            }
            remaining--
            value & 0xff
        }

        @Override
        int read(byte[] b, int off, int len) {
            if (remaining <= 0) {
                return -1
            }
            int n = (int) Math.min((long) len, remaining)
            Arrays.fill(b, off, off + n, value)
            remaining -= n
            n
        }
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
        for (int i = 0; i < 30; i++) {
            status = httpGet('/hello.php', ['User-Agent': SCANNER_UA])
            if (status == expected) {
                return
            }
            Thread.sleep(500)
        }
        assert status == expected : "scanner request kept answering ${status}, expected ${expected}"
    }

    private static long errorLogSize() {
        def res = CONTAINER.execInContainer('bash', '-c', "stat -c %s ${PHP_ERROR_LOG} 2>/dev/null || echo 0".toString())
        res.stdout.trim() as long
    }

    private static String errorLogSince(long offset) {
        // php-fpm writes the log from the worker; give it a moment to land.
        Thread.sleep(500)
        CONTAINER.execInContainer('bash', '-c', "tail -c +${offset + 1} ${PHP_ERROR_LOG}".toString()).stdout
    }

    private static void assertNativeFatal(String log, int limitMib) {
        String msg = "Allowed memory size of ${limitMib * MIB} bytes exhausted"
        assert log.contains(msg) : "expected the engine's out-of-memory error in the log; got: ${log.take(2000)}"
        assert !log.contains(DEFERRED_SUFFIX) : "the mitigation must be off; got: ${log.take(2000)}"
    }

    // Collect metric series until one named `name` arrives; returns them all.
    private static List<Metric> waitForMetricSeries(String name) {
        List<Metric> series = []
        for (int i = 0; i < 40 && !series.any { it.name == name }; i++) {
            assert httpGet('/hello.php') == 200 // keep the worker busy so telemetry flushes
            series.addAll(TelemetryHelpers.drainMetricSeries(CONTAINER, 'appsec_int_tests'))
        }
        series
    }

    // Collect metric series for `heartbeats` telemetry heartbeats.
    private static List<Metric> collectMetricSeries(int heartbeats) {
        String interval = CONTAINER.envMap['DD_TELEMETRY_HEARTBEAT_INTERVAL']
        assert interval : 'the container does not set DD_TELEMETRY_HEARTBEAT_INTERVAL'
        long deadline = System.currentTimeMillis() + heartbeats * ((interval as BigDecimal) * 1000).longValue()
        List<Metric> series = []
        while (System.currentTimeMillis() < deadline) {
            assert httpGet('/hello.php') == 200
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
