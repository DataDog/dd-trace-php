package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static java.time.Duration.ofSeconds
import static org.junit.jupiter.api.Assumptions.assumeTrue

/**
 * The PHP2Shell mitigation in FrankenPHP worker mode, where one PHP request
 * (the worker script's) spans every HTTP request: the request level is armed
 * at the worker's RINIT, before any WAF decision of an HTTP request, and must
 * then follow the decision of each HTTP request's request_init.
 *
 * Mode auto, the real helper and its default ruleset (run.sh drops
 * datadog.appsec.rules with APPSEC_P2S_WORKER_TEST, so remote config can
 * replace it), memory_limit=32M. The rule's action is switched through remote
 * config (ASM_DD) between block and monitor, and a runtime allocation inside
 * an HTTP request of the one worker crosses memory_limit.
 *
 * A fatal in a worker HTTP request ends the worker script, and FrankenPHP
 * runs it again on the same thread (new RINIT). So each switch is made after
 * the previous attack's restart, and /p2s_worker shows that the worker script
 * run, hence its RINIT, is the same from before the switch until the attack:
 * the attack's arming comes from its own HTTP request, not from a RINIT.
 */
@Testcontainers
@Slf4j
@EnabledIf('isZts84')
class Php2ShellFrankenphpWorkerTests {
    static boolean zts84 = variant.contains('zts') && phpVersion.contains('8.4')

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'frankenphp',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'frankenphp',
                    www_src: '_handlers',
            )
                    .withEnv('APPSEC_P2S_WORKER_TEST', '1')
                    .withEnv('DD_APPSEC_PHP2SHELL_MITIGATION', 'auto')
                    .withEnv('DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS', '1')

    private static final String PHP_ERROR_LOG = '/tmp/logs/php_error.log'
    private static final Target RC_TARGET = new Target('appsec_int_tests', 'integration', '')
    private static final String ASM_DD = 'datadog/2/ASM_DD/php2shell_rules/config'
    private static final String DEFERRED_SUFFIX = 'Datadog AppSec PHP2Shell mitigation deferred'
    private static final long MIB = 1024L * 1024L
    // The memory_limit run.sh sets, and one runtime allocation between it
    // (the soft limit) and the hard limit when armed (2 x 32 MiB), with room
    // for the worker's own usage.
    private static final long LIMIT = 32L * MIB
    private static final long ALLOC = 48L * MIB

    @BeforeAll
    static void beforeAll() {
        Wait.forLogMessage('.*serving initial configuration.*', 1)
                .withStartupTimeout(ofSeconds(30))
                .waitUntilReady(CONTAINER)
    }

    @Test
    void 'the worker follows the WAF decision of each HTTP request: block, monitor, block'() {
        assert httpGet('/') == 200
        Map<String, String> info = p2sInfo()
        log.info("PHP2Shell MINFO rows: ${info}")
        // Under auto, only a vulnerable runtime sends the address the rule
        // matches on. The runtime override needs datadog.appsec.testing,
        // which turns off the helper, so it cannot be used here.
        assumeTrue(info['PHP2Shell patched runtime'] == 'No',
                "this image's PHP carries the upstream fix; got ${info}")
        assert info['PHP2Shell mitigation mode'] == 'auto'

        // 1. Default ruleset, action mode block. The worker script's RINIT
        // ran before any decision (pending: armed).
        waitForDecision('block')
        Map<String, String> w1 = workerProbe()
        def (Trace t1, String log1) = attack()
        assertDeferred(t1, log1)

        // The fatal restarted the worker script, on the same thread, with a
        // RINIT under block: armed.
        Map<String, String> w2 = workerProbe()
        assert w2.worker != w1.worker : 'the fatal did not restart the worker script'
        assert w2.tid == w1.tid

        // 2. Monitor, on the worker script run armed at its RINIT: the HTTP
        // requests' decision disarms it.
        CONTAINER.applyRemoteConfig(RC_TARGET, [(ASM_DD): wafRuleset('monitor')])
        waitForDecision('monitor')
        assertSameRun(w2)
        def (Trace t2, String log2) = attack()
        assertNativeFatal(log2)
        assertP2sTags(t2, [memory_error: 'memory_limit', phase: 'runtime'])
        assert t2.first().metrics._sampling_priority_v1 == 2.0d

        // Restarted again, with a RINIT under monitor: not armed.
        Map<String, String> w3 = workerProbe()
        assert w3.worker != w2.worker : 'the fatal did not restart the worker script'
        assert w3.tid == w2.tid

        // 3. Block again, on the worker script run not armed at its RINIT:
        // the HTTP requests' decision arms it.
        CONTAINER.applyRemoteConfig(RC_TARGET, [(ASM_DD): wafRuleset('block')])
        waitForDecision('block')
        assertSameRun(w3)
        def (Trace t3, String log3) = attack()
        assertDeferred(t3, log3)
    }

    // The worker script run and thread serving the request (see
    // Php2ShellWorkerHandler).
    private static Map<String, String> workerProbe() {
        HttpResponse<String> resp = CONTAINER.httpClient.send(
                CONTAINER.buildReq('/p2s_worker').GET().build(), ofString())
        assert resp.statusCode() == 200
        def m = (resp.body() =~ /worker=(\w+) tid=(\d+) requests=(\d+)/)
        assert m.find() : "unexpected /p2s_worker body: ${resp.body()}"
        Map<String, String> res = [worker: m.group(1), tid: m.group(2), requests: m.group(3)]
        log.info("worker probe: ${res}")
        res
    }

    // Still the worker script run `before` (no restart since), one request
    // later or more.
    private static void assertSameRun(Map<String, String> before) {
        Map<String, String> now = workerProbe()
        assert now.worker == before.worker : "the worker script restarted: ${before} -> ${now}"
        assert now.tid == before.tid
        assert (now.requests as int) > (before.requests as int)
    }

    // A runtime allocation past LIMIT in an HTTP request of the worker; its
    // trace and the error log lines it wrote.
    private static List attack() {
        CONTAINER.clearTraces()
        long logOffset = errorLogSize()
        HttpRequest req = CONTAINER.buildReq("/p2s_oom?alloc=${ALLOC}")
                .timeout(Duration.ofSeconds(30)).GET().build()
        Trace trace = CONTAINER.traceFromRequest(req, ofString(), { HttpResponse<String> resp ->
            log.info("attack response status=${resp.statusCode()} body=${resp.body()?.take(200)}")
            assert !(resp.body() ?: '').contains('RAN') : 'the allocation raised no error'
        }, true)
        String errLog = errorLogSince(logOffset)
        log.info("attack: root meta=${trace.first().meta.findAll { it.key.contains('php2shell') }} " +
                "priority=${trace.first().metrics._sampling_priority_v1}")
        assertNoAppsecEvent(trace)
        [trace, errLog]
    }

    private static void assertDeferred(Trace trace, String log) {
        assert log.contains("Allowed memory size of ${LIMIT} bytes exhausted (${DEFERRED_SUFFIX}") :
                "expected the deferred fatal; got: ${log.take(2000)}"
        assertP2sTags(trace, [deferred: 'runtime'])
        assert trace.first().metrics._sampling_priority_v1 == 2.0d
    }

    private static void assertNativeFatal(String log) {
        assert log.contains("Allowed memory size of ${LIMIT} bytes exhausted") :
                "expected the engine's out-of-memory error in the log; got: ${log.take(2000)}"
        assert !log.contains(DEFERRED_SUFFIX) : "the mitigation must not be armed; got: ${log.take(2000)}"
    }

    // The PHP2Shell rows of the worker's phpinfo(). The HTTP request's own
    // request_init has run by then, so the WAF decision row is its answer.
    private static Map<String, String> p2sInfo() {
        HttpResponse<String> resp = CONTAINER.httpClient.send(
                CONTAINER.buildReq('/phpinfo').GET().build(), ofString())
        assert resp.statusCode() == 200
        def m = (resp.body() =~ /<td class="e">(PHP2Shell[^<]*?)\s*<\/td><td class="v">([^<]*?)\s*<\/td>/)
        Map<String, String> rows = [:]
        while (m.find()) {
            rows[m.group(1)] = m.group(2)
        }
        assert rows : 'no PHP2Shell rows in phpinfo()'
        rows
    }

    // Until the worker thread's decision is `expected`: the remote config is
    // polled every second, and the helper applies a ruleset some time after.
    private static void waitForDecision(String expected) {
        String decision = null
        for (int i = 0; i < 60; i++) {
            decision = p2sInfo()['PHP2Shell WAF decision']
            if (decision == expected) {
                return
            }
            Thread.sleep(500)
        }
        assert decision == expected : "the PHP2Shell WAF decision stayed ${decision}, expected ${expected}"
    }

    // An ASM_DD ruleset with a scanner rule and the PHP2Shell rule of the
    // default ruleset, its action's mode set to `mode`. As in
    // Php2ShellMitigationTests.
    private static Map wafRuleset(String mode) {
        [
                version: '2.2',
                metadata: [rules_version: '1.99.0'],
                rules: [
                        [
                                id: 'ua0-600-12x',
                                name: 'Arachni',
                                tags: [type: 'security_scanner', category: 'attack_attempt'],
                                conditions: [[
                                        parameters: [inputs: [[address: 'server.request.headers.no_cookies',
                                                               key_path: ['user-agent']]],
                                                     regex: '^Arachni\\/v'],
                                        operator: 'match_regex',
                                ]],
                                transformers: [],
                        ],
                        [
                                id: 'dd-php2shell-001',
                                name: 'PHP2Shell mitigation for vulnerable PHP runtimes',
                                tags: [type: 'php2shell_mitigation', category: 'vulnerability_mitigation',
                                       module: 'waf'],
                                conditions: [[
                                        parameters: [inputs: [[address: 'php.php2shell.vulnerable']],
                                                     list: ['full', 'partial']],
                                        operator: 'exact_match',
                                ]],
                                transformers: [],
                                on_match: ['php2shell_block'],
                        ],
                ],
                actions: [[id: 'php2shell_block', type: 'php2shell_mitigation', parameters: [mode: mode]]],
        ]
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

    private static int httpGet(String path) {
        CONTAINER.httpClient.send(CONTAINER.buildReq(path).GET().build(), ofString()).statusCode()
    }

    private static long errorLogSize() {
        def res = CONTAINER.execInContainer('bash', '-c', "stat -c %s ${PHP_ERROR_LOG} 2>/dev/null || echo 0".toString())
        res.stdout.trim() as long
    }

    private static String errorLogSince(long offset) {
        // Give the log line a moment to land.
        Thread.sleep(500)
        CONTAINER.execInContainer('bash', '-c', "tail -c +${offset + 1} ${PHP_ERROR_LOG}".toString()).stdout
    }
}
