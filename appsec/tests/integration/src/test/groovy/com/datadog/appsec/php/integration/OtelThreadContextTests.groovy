package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.json.JsonSlurper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.containers.Container.ExecResult
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse
import java.util.concurrent.CompletableFuture
import java.util.concurrent.TimeUnit

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
class OtelThreadContextTests {
    private static final String PID_FILE = '/tmp/pid'
    private static final String GDB_SCRIPT =
            '/project/appsec/tests/integration/src/test/resources/otel_context_gdb.py'
    private static final String GDB_TIMEOUT = '20s'
    private static final String EXPECTED_PROCESS_CONTEXT_SIGNATURE = 'OTEL_CTX'
    private static final String EXPECTED_PROCESS_CONTEXT_VERSION = '2'

    static boolean disabled = phpVersion != '8.3'

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @AfterEach
    void afterEach() {
        CONTAINER.clearTraces()
    }

    @Test
    void 'otel thread context matches trace ids during regular request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_regular.php')
        boolean released = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            released = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!released) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    @Test
    void 'otel thread context matches trace ids during user request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_user_request.php')
        boolean released = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            released = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.outer_span_id != responseBody.span_id
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!released) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    @Test
    void 'otel process context shared memory has expected threadlocal schema and version'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_regular.php')
        boolean released = false

        try {
            Map<String, String> processContext = inspectProcessContext(pausedRequest.pid)
            continuePausedRequest(pausedRequest.pid)
            released = true

            HttpResponse<String> response = awaitResponse(pausedRequest)
            parseJsonResponse(response)

            assert processContext.present == 'true'
            assert processContext.signature == EXPECTED_PROCESS_CONTEXT_SIGNATURE
            assert processContext.version == EXPECTED_PROCESS_CONTEXT_VERSION
            assert processContext.payload_size.toInteger() > 0
            assert processContext.published_at.toBigInteger() > 0
            assert processContext.has_threadlocal_schema_key == 'true'
            assert processContext.has_threadlocal_schema_value == 'true'
            assert processContext.has_threadlocal_attribute_key_map == 'true'
            assert processContext.has_local_root_span_key == 'true'
        } finally {
            if (!released) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    private static void assertThreadContextMatchesResponse(
            Map<String, String> threadContext, Map responseBody) {
        assert responseBody.waited == true
        assert threadContext.ctx != '0x0'
        assert threadContext.valid == '1'
        assert threadContext.attrs_data_size.toInteger() >= 18
        assert threadContext.attr0_key == '0'
        assert threadContext.attr0_len == '16'
        assert threadContext.trace_id == responseBody.trace_id
        assert threadContext.span_id == responseBody.span_id
        assert threadContext.local_root_span_id == responseBody.local_root_span_id
    }

    private static Map parseJsonResponse(HttpResponse<String> response) {
        assert response.statusCode() == 200
        new JsonSlurper().parseText(response.body()) as Map
    }

    private static PausedRequest startPausedRequest(String path) {
        CONTAINER.execInContainer('rm', '-f', PID_FILE)

        def request = CONTAINER.buildReq(path).GET().build()
        CompletableFuture<HttpResponse<String>> responseFuture =
                CONTAINER.httpClient.sendAsync(request, ofString())

        new PausedRequest(
                pid: waitForPausedPid(responseFuture),
                responseFuture: responseFuture)
    }

    private static String waitForPausedPid(CompletableFuture<HttpResponse<String>> responseFuture) {
        long deadline = System.currentTimeMillis() + 15_000

        while (System.currentTimeMillis() < deadline) {
            if (responseFuture.isDone()) {
                HttpResponse<String> response = responseFuture.getNow(null)
                throw new AssertionError(
                        "Request completed before the debugger pause: HTTP ${response.statusCode()}\n${response.body()}".toString())
            }

            ExecResult res = CONTAINER.execInContainer(
                    'bash', '-lc',
                    "test -s ${PID_FILE} && cat ${PID_FILE} || true".toString())
            if (res.exitCode == 0) {
                String pid = res.stdout.trim()
                if (pid) {
                    return pid
                }
            }
            Thread.sleep(100)
        }

        throw new AssertionError('Timed out waiting for the paused PHP worker pid')
    }

    private static HttpResponse<String> awaitResponse(PausedRequest pausedRequest) {
        pausedRequest.responseFuture.get(30, TimeUnit.SECONDS)
    }

    private static Map<String, String> inspectThreadLocalAndContinue(String pid) {
        List<String> commands = [
                'set pagination off',
                'otel-thread-context',
                'ddappsec-continue',
                'detach',
                'quit',
        ]

        ExecResult res = runGdb(pid, commands)
        parseKeyValueOutput(res.stdout)
    }

    private static void continuePausedRequest(String pid) {
        runGdb(pid, [
                'set pagination off',
                'ddappsec-continue',
                'detach',
                'quit',
        ])
    }

    private static void continuePausedRequestQuietly(String pid) {
        try {
            continuePausedRequest(pid)
        } catch (Throwable ignored) {
            // The original failure is more useful than a best-effort cleanup error.
        }
    }

    private static ExecResult runGdb(String pid, List<String> commands) {
        List<String> args = [
                'timeout',
                GDB_TIMEOUT,
                'gdb',
                '--batch',
                '--quiet',
                '-p',
                pid,
                '-ex',
                "python exec(open('${GDB_SCRIPT}').read())".toString(),
        ]
        commands.each {
            args.add('-ex')
            args.add(it)
        }

        ExecResult res = CONTAINER.execInContainer(args as String[])
        if (res.exitCode != 0 || res.stderr =~ /(Traceback|Python Exception|No symbol|Undefined command)/) {
            throw new AssertionError(
                    "gdb failed with exit code ${res.exitCode}\nstdout:\n${res.stdout}\nstderr:\n${res.stderr}".toString())
        }
        res
    }

    private static Map<String, String> inspectProcessContext(String pid) {
        ExecResult res = runGdb(pid, [
                'set pagination off',
                'otel-process-context',
                'detach',
                'quit',
        ])
        parseKeyValueOutput(res.stdout)
    }

    private static Map<String, String> parseKeyValueOutput(String output) {
        Map<String, String> result = [:]
        output.readLines().each { String line ->
            if (line ==~ /^[A-Za-z_][A-Za-z0-9_]*=.*/) {
                int idx = line.indexOf('=')
                result[line.substring(0, idx).trim()] = line.substring(idx + 1).trim()
            }
        }
        result
    }

    private static class PausedRequest {
        String pid
        CompletableFuture<HttpResponse<String>> responseFuture
    }
}
