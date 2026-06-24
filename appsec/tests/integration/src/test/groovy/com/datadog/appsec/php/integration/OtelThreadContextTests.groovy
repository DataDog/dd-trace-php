package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.json.JsonSlurper
import groovy.util.logging.Slf4j
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
    private static final String LOCAL_ROOT_SPAN_ID_ATTRIBUTE_KEY = 'datadog.local_root_span_id'
    private static final String SERVICE_NAME_ATTRIBUTE_KEY = 'service.name'
    private static final String SERVICE_VERSION_ATTRIBUTE_KEY = 'service.version'
    private static final String DEPLOYMENT_ENV_ATTRIBUTE_KEY = 'deployment.environment.name'
    private static final String EXPECTED_SERVICE_NAME = 'appsec_int_tests'
    private static final String EXPECTED_SERVICE_VERSION = 'otel-context-test'
    private static final String EXPECTED_ENV = 'integration'
    private static final String EXPECTED_ATTRIBUTE_KEY_MAP = [
            LOCAL_ROOT_SPAN_ID_ATTRIBUTE_KEY,
            SERVICE_NAME_ATTRIBUTE_KEY,
            SERVICE_VERSION_ATTRIBUTE_KEY,
            DEPLOYMENT_ENV_ATTRIBUTE_KEY,
    ].join(',')

    static boolean disabled = phpVersion != '8.3'

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            ).withEnv('DD_TRACE_REPORT_HOSTNAME', 'true')
                    .withEnv('DD_VERSION', EXPECTED_SERVICE_VERSION)

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'otel thread context matches trace ids during regular request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_regular.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    @Test
    void 'otel thread context matches trace ids during user request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_user_request.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.outer_span_id != responseBody.span_id
            assertThreadContextMatchesResponse(threadContext, [
                    waited: responseBody.waited,
                    trace_id: responseBody.outer_trace_id,
                    span_id: responseBody.outer_span_id,
                    local_root_span_id: responseBody.outer_local_root_span_id,
            ])
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    @Test
    void 'otel process context shared memory has expected metadata and threadlocal attributes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context_regular.php')
        boolean requestContinued = false

        try {
            Map<String, String> processContext = inspectProcessContext(pausedRequest.pid)
            continuePausedRequest(pausedRequest.pid)
            requestContinued = true

            HttpResponse<String> response = awaitResponse(pausedRequest)
            parseJsonResponse(response)

            assert processContext.present == 'true'
            assert processContext.signature == 'OTEL_CTX'
            assert processContext.version == '2'
            assert processContext.payload_size.toInteger() > 0
            assert processContext.published_at.toBigInteger() > 0
            assert processContext['telemetry.sdk.language'] == 'php'
            assert processContext['telemetry.sdk.version'] == expectedTracerVersion()
            assert processContext['host.name'] == expectedContainerHostname()
            assert processContext['threadlocal.schema_version'] == 'tlsdesc_v1_dev'
            assert processContext['threadlocal.attribute_key_map'] == EXPECTED_ATTRIBUTE_KEY_MAP
        } finally {
            if (!requestContinued) {
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
        assert threadContext.trace_id == responseBody.trace_id
        assert threadContext.span_id == responseBody.span_id
        assert threadContext[LOCAL_ROOT_SPAN_ID_ATTRIBUTE_KEY] == responseBody.local_root_span_id
        assert threadContext[SERVICE_NAME_ATTRIBUTE_KEY] == EXPECTED_SERVICE_NAME
        assert threadContext[SERVICE_VERSION_ATTRIBUTE_KEY] == EXPECTED_SERVICE_VERSION
        assert threadContext[DEPLOYMENT_ENV_ATTRIBUTE_KEY] == EXPECTED_ENV
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

    private static String expectedTracerVersion() {
        ExecResult res = CONTAINER.execInContainer('bash', '-lc', 'cat /project/VERSION')
        assert res.exitCode == 0
        res.stdout.trim()
    }

    private static String expectedContainerHostname() {
        ExecResult res = CONTAINER.execInContainer('hostname')
        assert res.exitCode == 0
        res.stdout.trim()
    }

    private static Map<String, String> parseKeyValueOutput(String output) {
        Map<String, String> result = [:]
        output.readLines().each { String line ->
            if (line ==~ /^[A-Za-z_][A-Za-z0-9_.]*=.*/) {
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
