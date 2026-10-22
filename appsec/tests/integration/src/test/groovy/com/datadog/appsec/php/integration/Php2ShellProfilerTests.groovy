package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.PhpFpm
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Assumptions
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
 * The PHP2Shell mitigation (CVE-2026-109618) next to datadog-profiling with
 * allocation profiling on, under php-fpm. Both install Zend MM custom handlers
 * per request: the profiler in its RINIT and removes them in its RSHUTDOWN,
 * the mitigation at the end of appsec's RINIT and removes them at the start of
 * appsec's RSHUTDOWN (startup windows install and remove their own before any
 * RINIT). That is LIFO in either module order, and a deferred raise only
 * changes state, never the handlers. A broken unwind leaves one extension's
 * handlers on the heap after the request and the next install chains to them
 * as its own "previous" handlers: every allocation then recurses until the
 * worker dies of SIGSEGV, or the profiler logs that it could not restore its
 * handlers.
 *
 * Each case runs once per module order: the installer's (ddtrace, profiler,
 * ddappsec), where the profiler's RINIT runs before appsec's, and the reverse.
 * It triggers a deferral and then sends more requests to the same single
 * worker (pm.max_requests = 0), asserting the deferred fatal, that the worker
 * keeps answering with the same pid (or, after a startup deferral, which ends
 * the worker, that its replacement does), that the FPM log has no worker
 * killed by a signal, that the profiler logged enabling allocation profiling
 * for each of those requests (and, when its RINIT runs after appsec's, finding
 * appsec's handlers to chain to) and no restore failure, and that the deferred
 * metric with its phase tag reaches telemetry.
 *
 * The profiler logs to a copy of the stderr it saw at MINIT, which php-fpm
 * runs in the master before it points fd 2 at its error log, so neither the
 * FPM log nor catch_workers_output sees those lines. php-fpm is started with
 * its output appended to PROFILER_LOG instead, and with the profiler's log
 * level raised to trace, the level of its per-request "enabled" line.
 *
 * Needs the profiler build: run with gradle -PwithProfiler.
 *
 * The methods run in name order; each waits for a metric series with a phase
 * tag that only it emits.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
@TestMethodOrder(MethodOrderer.MethodName)
class Php2ShellProfilerTests {
    static boolean disabled = System.getProperty('WITH_PROFILER') != 'true' ||
            !(phpVersion in ['7.4', '8.3', '8.5'])

    @Container
    public static final AppSecContainer CONTAINER = new AppSecContainer(
            workVolume: this.name,
            baseTag: 'apache2-fpm-php',
            phpVersion: phpVersion,
            phpVariant: variant,
            www: 'base',
            profiler: true,
    )

    private static final String MODE = 'on'
    private static final String POOL = '/etc/php-fpm.d/www.conf'
    private static final String PROFILER_LAST_INI = '/etc/php/php-p2s-profiler-last.ini'
    private static final String PROFILER_FIRST_INI = '/etc/php/php-p2s-profiler-first.ini'
    private static final String PHP_ERROR_LOG = '/tmp/logs/php_error.log'
    private static final String FPM_LOG = '/tmp/logs/php_fpm_error.log'
    private static final String PROFILER_LOG = '/tmp/logs/php_fpm_p2s_output.log'
    private static final long MIB = 1024L * 1024L
    // Large enough that the parse dominates the base usage of the request.
    private static final long PARSE_STR_LEN = 4_000_000L
    private static final long BODY_FIELD_LEN = 5_000_000L
    private static final String METRIC_DEFERRED = 'appsec.php2shell.soft_limit_deferred'
    private static final String DEFERRED_MSG = 'bytes exhausted (Datadog AppSec PHP2Shell mitigation deferred'
    // allocation_le83.rs / allocation_ge84.rs, at the end of the profiler's
    // RINIT (trace), on finding another extension's handlers there (debug) and
    // on failing to remove its handlers (warn).
    private static final String PROF_ENABLED = 'Memory allocation profiling enabled.'
    private static final String PROF_CHAINED = 'Found another extension using the ZendMM custom handler hook'
    private static final String PROF_NOT_RESTORED = 'could not be restored'
    private static final int FOLLOW_UPS = 5

    @Test
    void 'a_runtime: a parse_str() deferral leaves the worker and the profiler working'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        eachModuleOrder { String ini ->
            // memory_limit=-1 at startup: no startup window arms, so only the
            // RINIT install is in place (with the profiler's RINIT before or
            // after it). A finite one: the GET startup window armed and was
            // removed before any RINIT.
            ['-1', '256M'].each { String startLimit ->
                withPool(fpm, ini, startLimit) {
                    FpmLogs logs = markLogs()
                    Map<String, Long> cal = parsed(httpGet("/php2shell_runtime.php?len=${PARSE_STR_LEN}"))
                    long softMib = (long) Math.ceil((cal.base + 0.6d * (cal.peak - cal.base)) / MIB)
                    log.info("runtime: ini=${ini} start=${startLimit} cal=${cal} soft=${softMib}M")
                    assert softMib * MIB < cal.peak : "calibration: soft ${softMib}M not below peak ${cal}"

                    HttpResponse<String> r = httpGet("/php2shell_runtime.php?len=${PARSE_STR_LEN}&ml=${softMib}M")
                    assert !r.body().contains('PARSED') :
                            "expected the deferred fatal; the script completed: ${r.statusCode()} ${r.body()}"
                    assertDeferredFatal(logs, softMib * MIB)
                    assertWorkerSurvives(cal.pid, logs, ini)
                }
            }
        }
        assertDeferredMetric('runtime')
    }

    @Test
    void 'b_startup: a POST parse deferral leaves the next worker and the profiler working'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        eachModuleOrder { String ini ->
            long peak = withPool(fpm, ini, '256M') {
                String body = postBody().body()
                def m = (body =~ /peak=(\d+)/)
                assert m.find() : "could not read peak from calibration body: [${body.trim()}]"
                m.group(1) as long
            }
            long limitMib = Math.max(4L, Math.round(peak * 0.7d / MIB))
            log.info("startup: ini=${ini} peak=${peak} limit=${limitMib}M")
            withPool(fpm, ini, "${limitMib}M") {
                FpmLogs logs = markLogs()
                HttpResponse<String> r = postBody()
                // php_request_startup() fails: php-fpm ends the request with no
                // output and the worker exits with code 70.
                assert r.statusCode() == 200 && !r.body().contains('post_fields') :
                        "expected the deferred fatal (200, empty body); got ${r.statusCode()} ${r.body()}"
                assertDeferredFatal(logs, limitMib * MIB)
                Map<String, Long> first = parsed(httpGet('/php2shell_runtime.php'))
                assertWorkerSurvives(first.pid, logs, ini)
            }
        }
        assertDeferredMetric('startup')
    }

    @Test
    void 'c_request_parse_body: a request_parse_body() deferral leaves the worker and the profiler working'() {
        Assumptions.assumeTrue((phpVersion as BigDecimal) >= 8.4, 'request_parse_body() is PHP 8.4+')
        PhpFpm fpm = new PhpFpm(CONTAINER)
        eachModuleOrder { String ini ->
            withPool(fpm, ini, '256M') {
                FpmLogs logs = markLogs()
                Map<String, Long> cal = parsed(putBody(''))
                long softMib = (long) Math.ceil((cal.base + 0.6d * (cal.peak - cal.base)) / MIB)
                log.info("request_parse_body: ini=${ini} cal=${cal} soft=${softMib}M")
                assert softMib * MIB < cal.peak : "calibration: soft ${softMib}M not below peak ${cal}"

                HttpResponse<String> r = putBody("${softMib}M")
                assert !r.body().contains('PARSED') :
                        "expected the deferred fatal; the script completed: ${r.statusCode()} ${r.body()}"
                assertDeferredFatal(logs, softMib * MIB)
                assertWorkerSurvives(cal.pid, logs, ini)
            }
        }
        assertDeferredMetric('request_parse_body')
    }

    // Further requests on the pool's single worker: each completes in the
    // worker with pid `pid`, and nothing in the FPM log since `logs` shows a
    // worker killed by a signal. In the profiler's log since `logs`, nothing
    // shows it failing to restore its handlers, and it enabled allocation
    // profiling for each of these requests; with `ini` loading it after
    // ddappsec, it found appsec's handlers in place each time, and with the
    // profiler first, never (appsec's startup handlers are gone by then).
    private static void assertWorkerSurvives(long pid, FpmLogs logs, String ini) {
        FpmLogs before = markLogs()
        for (int i = 0; i < FOLLOW_UPS; i++) {
            HttpResponse<String> r = httpGet('/php2shell_runtime.php')
            assert r.statusCode() == 200 && r.body().contains("PARSED pid=${pid} ") :
                    "follow-up request ${i + 1} not served by worker ${pid}: ${r.statusCode()} " +
                            "[${r.body()?.trim()?.take(200)}]; FPM log: ${fpmProblems(logs)}"
        }
        assert httpGet('/hello.php').statusCode() == 200
        String problems = fpmProblems(logs)
        assert problems.isEmpty() : "FPM log: ${problems}"

        String all = logSince(PROFILER_LOG, logs.profilerOffset)
        List<String> restore = all.readLines().findAll { it.contains(PROF_NOT_RESTORED) }
        assert restore.isEmpty() : "profiler log: ${restore.join('\n')}"
        List<String> lines = logSince(PROFILER_LOG, before.profilerOffset).readLines()
        int enabled = lines.count { it.contains(PROF_ENABLED) }
        int chained = lines.count { it.contains(PROF_CHAINED) }
        log.info("profiler log (${ini}): enabled=${enabled} chained=${chained}")
        assert enabled >= FOLLOW_UPS + 1 :
                "profiler enabled allocation profiling ${enabled} times for ${FOLLOW_UPS + 1} requests; " +
                        "profiler log: ${lines.takeRight(40).join('\n')}"
        if (ini == PROFILER_LAST_INI) {
            assert chained == enabled : "profiler found appsec's handlers in ${chained} of ${enabled} requests"
        } else {
            assert chained == 0 : "profiler found other handlers in ${chained} of ${enabled} requests"
        }
    }

    // Wait for the deferred metric with the tag phase:`phase`.
    private static void assertDeferredMetric(String phase) {
        List<Metric> series = []
        Closure<Metric> find = {
            series.find { it.name == METRIC_DEFERRED && "phase:${phase}".toString() in it.tags }
        }
        for (int i = 0; i < 40 && !find(); i++) {
            assert httpGet('/hello.php').statusCode() == 200 // keep the worker busy so telemetry flushes
            series.addAll(TelemetryHelpers.drainMetricSeries(CONTAINER, 'appsec_int_tests'))
        }
        Metric m = find()
        assert m != null : "metric ${METRIC_DEFERRED} with phase:${phase} not received; got " +
                "${series.findAll { it.name.startsWith('appsec.php2shell.') }.collect { [it.name, it.tags] }}"
        assert m.namespace == 'appsec'
        assert m.type == 'count'
        assert m.points[0][1] >= 1.0
        assert "mode:${MODE}".toString() in m.tags
    }

    // Run `body` once with a copy of php.ini in its module order (the
    // profiler loaded after ddappsec) and once with a copy where the profiler
    // is loaded before ddappsec, as the installer orders them. Both copies set
    // the profiler's log level to trace.
    private static void eachModuleOrder(Closure body) {
        def res = CONTAINER.execInContainer('bash', '-c',
                "grep -v '^datadog.profiling.log_level' ${PhpFpm.DEFAULT_INI} > ${PROFILER_LAST_INI} && " +
                        "echo datadog.profiling.log_level=trace >> ${PROFILER_LAST_INI} && " +
                        "grep -v '^extension=.*datadog-profiling.so' ${PROFILER_LAST_INI} | " +
                        "sed '/^extension=.*ddappsec.so/i extension=/profiler/datadog-profiling.so' " +
                        "> ${PROFILER_FIRST_INI}".toString())
        assert res.exitCode == 0 : res.stderr
        [PROFILER_LAST_INI, PROFILER_FIRST_INI].each { String ini ->
            assertModuleOrder(ini, ini == PROFILER_FIRST_INI)
            body(ini)
        }
    }

    private static void assertModuleOrder(String ini, boolean profilerFirst) {
        def res = CONTAINER.execInContainer('php', '-c', ini, '-r',
                'echo implode(",", get_loaded_extensions());')
        List<String> exts = res.stdout.trim().split(',') as List<String>
        int prof = exts.indexOf('datadog-profiling')
        int appsec = exts.indexOf('ddappsec')
        assert prof >= 0 && appsec >= 0 : "profiler or appsec not loaded with ${ini}: ${exts} ${res.stderr}"
        assert (prof < appsec) == profilerFirst : "unexpected module order with ${ini}: ${exts}"
    }

    // Run `body` with php-fpm restarted with `ini` and the pool pinned to one
    // static worker that is never recycled, memory_limit `memoryLimit` (as
    // php_value, so that ini_set() can change it), post_max_size=32M, worker
    // stderr in the FPM log and the mitigation forced on through the pool env,
    // and the master's output (with the profiler's log) appended to
    // PROFILER_LOG. The pool file is restored and php-fpm restarted with the
    // default php.ini afterwards.
    private static <T> T withPool(PhpFpm fpm, String ini, String memoryLimit, Closure<T> body) {
        List<String> lines = [
                "php_value[memory_limit] = ${memoryLimit}".toString(),
                'php_admin_value[post_max_size] = 32M',
                "env[DD_APPSEC_PHP2SHELL_MITIGATION] = ${MODE}".toString(),
                'catch_workers_output = yes',
        ]
        CONTAINER.execInContainer('cp', POOL, "${POOL}.p2s".toString())
        try {
            fpm.setPoolValue('pm.max_children', '1')
            fpm.setPoolValue('pm.max_requests', '0')
            CONTAINER.execInContainer('bash', '-c',
                    "cat >> ${POOL} <<'EOF'\n${lines.join('\n')}\nEOF".toString())
            fpm.restart([:], ini, PROFILER_LOG)
            return body()
        } finally {
            CONTAINER.execInContainer('bash', '-c', "mv -f ${POOL}.p2s ${POOL}".toString())
            fpm.restart()
        }
    }

    // The base usage, peak and pid reported by php2shell_runtime.php.
    private static Map<String, Long> parsed(HttpResponse<String> r) {
        def m = (r.body() =~ /PARSED pid=(\d+) fields=\d+ base=(\d+) peak=(\d+)/)
        assert m.find() : "php2shell_runtime.php did not complete: ${r.statusCode()} [${r.body()?.trim()?.take(500)}]"
        [pid: m.group(1) as long, base: m.group(2) as long, peak: m.group(3) as long]
    }

    private static HttpResponse<String> httpGet(String path) {
        send(CONTAINER.buildReq(path).GET().build())
    }

    // A urlencoded POST to php2shell_sink.php with one field name of
    // BODY_FIELD_LEN bytes, parsed in request startup.
    private static HttpResponse<String> postBody() {
        send(CONTAINER.buildReq('/php2shell_sink.php')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofByteArray(fieldBody()))
                .build())
    }

    // The same body with PUT, which request startup leaves alone, to
    // php2shell_runtime.php, which parses it with request_parse_body() after
    // applying memory_limit `ml` (if not empty).
    private static HttpResponse<String> putBody(String ml) {
        send(CONTAINER.buildReq("/php2shell_runtime.php?rpb=1&ml=${ml}")
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .PUT(HttpRequest.BodyPublishers.ofByteArray(fieldBody()))
                .build())
    }

    private static byte[] fieldBody() {
        byte[] b = new byte[(int) BODY_FIELD_LEN + 6]
        b[0] = (byte) 0x66 // 'f'
        Arrays.fill(b, 1, (int) BODY_FIELD_LEN + 1, (byte) 0x5A) // 'Z'
        System.arraycopy('[k]=1'.bytes, 0, b, (int) BODY_FIELD_LEN + 1, 5)
        b
    }

    private static HttpResponse<String> send(HttpRequest req) {
        HttpResponse<String> r = CONTAINER.httpClient.send(req, ofString())
        log.info("${req.method()} ${req.uri().rawPath} -> ${r.statusCode()} [${r.body()?.trim()?.take(160)}]")
        r
    }

    // Offsets into the PHP error log, the FPM log and the profiler's log, to
    // read what a case appended to them.
    private static class FpmLogs {
        long phpErrorOffset
        long fpmOffset
        long profilerOffset
    }

    private static FpmLogs markLogs() {
        new FpmLogs(phpErrorOffset: fileSize(PHP_ERROR_LOG), fpmOffset: fileSize(FPM_LOG),
                profilerOffset: fileSize(PROFILER_LOG))
    }

    private static void assertDeferredFatal(FpmLogs logs, long soft) {
        String err = logSince(PHP_ERROR_LOG, logs.phpErrorOffset)
        assert err.contains("Allowed memory size of ${soft} ${DEFERRED_MSG}") :
                "expected the deferred fatal for ${soft} bytes in the PHP error log; got: ${err.take(2000)}"
    }

    // Lines of the FPM log since `logs` that show a worker killed by a signal.
    private static String fpmProblems(FpmLogs logs) {
        logSince(FPM_LOG, logs.fpmOffset).readLines().findAll {
            it.contains('exited on signal') || it.contains('SIGSEGV')
        }.join('\n')
    }

    private static long fileSize(String path) {
        CONTAINER.execInContainer('bash', '-c', "stat -c %s ${path} 2>/dev/null || echo 0".toString())
                .stdout.trim() as long
    }

    private static String logSince(String path, long offset) {
        // php-fpm writes the logs from the worker; give them a moment to land.
        Thread.sleep(500)
        CONTAINER.execInContainer('bash', '-c', "tail -c +${offset + 1} ${path}".toString()).stdout
    }
}
