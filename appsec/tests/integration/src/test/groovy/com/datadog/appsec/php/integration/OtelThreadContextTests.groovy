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
    private static final String PHASE_FILE = '/tmp/otel_context_phase'
    private static final String GDB_SCRIPT =
            '/project/appsec/tests/integration/src/test/resources/otel_context_gdb.py'
    private static final String GDB_TIMEOUT = '20s'
    private static final String DISTRIBUTED_TRACE_ID = '11111111111111112222222222222222'
    private static final String THREADLOCAL_ATTRIBUTE_KEY_MAP = [
            'datadog.local_root_span_id',
            'service.name',
            'service.version',
            'deployment.environment.name',
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
            )
            .withEnv('DD_TRACE_REPORT_HOSTNAME', 'true')
            .withEnv('DD_SERVICE', 'otel-thread-context-service')
            .withEnv('DD_VERSION', '1.2.3')
            .withEnv('DD_ENV', 'otel-thread-context-env')

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    /**
     * Baseline request-root check: the auto-created request span should publish a
     * complete OTel TLS record before any explicit stack or context manipulation.
     */
    @Test
    void 'otel thread context matches trace ids during regular request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/regular.php')
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

    /**
     * UserRequest creates a separate local root stack. This protects the expectation
     * that trace/span ids follow that stack while resource attrs keep following the
     * entrypoint root semantics used for sidecar/process context publication.
     */
    @Test
    void 'otel thread context matches trace ids during user request lifecycle'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/user_request.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            // local_root_span_id follows the currently active stack's root span.
            assert responseBody.local_root_span_id == responseBody.span_id
            assert responseBody.service_name != responseBody.user_request_service_name
            assert responseBody.service_version != responseBody.user_request_service_version
            assert responseBody.deployment_environment_name !=
                    responseBody.user_request_deployment_environment_name
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Runtime config mutation goes through the INI alter callbacks in ddtrace.c.
     * The OTel attrs should follow those effective service/env/version values.
     */
    @Test
    void 'otel thread context reflects mid request service env and version ini changes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/runtime_service_env_changes.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.service_name == 'otel-thread-context-updated-service'
            assert responseBody.service_version == '2.3.4'
            assert responseBody.deployment_environment_name == 'otel-thread-context-updated-env'
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Entrypoint root property writes also feed the sidecar-facing root span data.
     * This verifies the same service/env/version values are reflected in OTel TLS.
     */
    @Test
    void 'otel thread context reflects root span service env and version changes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/root_span_service_env_changes.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.service_name == 'otel-thread-context-root-service'
            assert responseBody.service_version == '3.4.5'
            assert responseBody.deployment_environment_name == 'otel-thread-context-root-env'
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Nested roots may have their own mutable service/env values, but OTel attrs are
     * expected to mirror entrypoint/sidecar semantics rather than the nested root.
     */
    @Test
    void 'otel thread context keeps entrypoint root service env on nested root changes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/nested_root_service_env_changes.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.original_service_name != responseBody.entrypoint_service_name
            assert responseBody.original_deployment_environment_name != responseBody.entrypoint_deployment_environment_name
            assert responseBody.nested_service_name != responseBody.entrypoint_service_name
            assert responseBody.nested_deployment_environment_name != responseBody.entrypoint_deployment_environment_name
            assert threadContext['service.name'] == responseBody.entrypoint_service_name
            assert threadContext['service.name'] != responseBody.original_service_name
            assert threadContext['service.name'] != responseBody.nested_service_name
            assert threadContext['deployment.environment.name'] == responseBody.entrypoint_deployment_environment_name
            assert threadContext['deployment.environment.name'] != responseBody.original_deployment_environment_name
            assert threadContext['deployment.environment.name'] != responseBody.nested_deployment_environment_name

            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Header consumption updates an already-active root trace id through
     * ddtrace_apply_distributed_tracing_result(); this guards that OTel is republished.
     */
    @Test
    void 'otel thread context reflects trace id changes from distributed tracing'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/distributed_tracing.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.original_trace_id != DISTRIBUTED_TRACE_ID
            assert threadContext.trace_id == DISTRIBUTED_TRACE_ID
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Manual distributed tracing context bypasses header extraction and mutates the
     * active root directly. This covers the separate setter path for OTel trace ids.
     */
    @Test
    void 'otel thread context reflects trace id changes from manual distributed tracing context'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/set_distributed_tracing_context.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.original_trace_id != DISTRIBUTED_TRACE_ID
            assert responseBody.trace_id == DISTRIBUTED_TRACE_ID
            assert threadContext.trace_id == DISTRIBUTED_TRACE_ID
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Direct RootSpanData::$traceId writes go through the PHP property handler, not
     * the distributed tracing APIs. The TLS trace id must still be updated.
     */
    @Test
    void 'otel thread context reflects direct root span trace id changes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/root_trace_id_changes.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.original_trace_id != DISTRIBUTED_TRACE_ID
            assert threadContext.trace_id == DISTRIBUTED_TRACE_ID
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Starting a child span should only advance the active span id in the attached
     * record; trace id and local root span id should remain tied to the root.
     */
    @Test
    void 'otel thread context reflects active child span id changes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/subspan.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert threadContext.span_id == responseBody.child_span_id
            assert responseBody.span_id != responseBody.local_root_span_id
            assert responseBody.trace_id == responseBody.root_trace_id
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Closing the active child span exercises the close path that moves stack->active
     * back to the parent and republishes that parent span id into OTel TLS.
     */
    @Test
    void 'otel thread context restores parent span id after child span close'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/closed_subspan.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.child_span_id != responseBody.span_id
            assert threadContext.span_id == responseBody.local_root_span_id
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Dropping the active child span uses a separate stack mutation path from close.
     * This guards the OTel parent-span restoration in ddtrace_drop_span().
     */
    @Test
    void 'otel thread context restores parent span id after child span drop'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/dropped_subspan.php')
        boolean requestContinued = false

        try {
            Map<String, String> threadContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.dropped == true
            assert responseBody.child_span_id != responseBody.span_id
            assert threadContext.span_id == responseBody.local_root_span_id
            assertThreadContextMatchesResponse(threadContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(pausedRequest.pid)
            }
        }
    }

    /**
     * Explicit stack switches should detach TLS when the target stack has no active
     * root, then republish the correct root record when switching between root stacks.
     */
    @Test
    void 'otel thread context detaches on empty stack and republishes on explicit stack switch'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/stack_switches.php')
        String cleanupPid = pausedRequest.pid
        boolean requestContinued = false

        try {
            Map<String, String> emptyContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            assert emptyContext.ctx == '0x0'

            String restoredEntrypointPid = waitForPausedPid(
                    pausedRequest.responseFuture,
                    'entrypoint-restored')
            cleanupPid = restoredEntrypointPid
            Map<String, String> restoredEntrypointContext =
                    inspectThreadLocalAndContinue(restoredEntrypointPid)

            String switchedEntrypointPid = waitForPausedPid(
                    pausedRequest.responseFuture,
                    'entrypoint-switched')
            cleanupPid = switchedEntrypointPid
            Map<String, String> switchedEntrypointContext =
                    inspectThreadLocalAndContinue(switchedEntrypointPid)

            String switchedNestedPid = waitForPausedPid(
                    pausedRequest.responseFuture,
                    'nested-switched')
            cleanupPid = switchedNestedPid
            Map<String, String> switchedNestedContext =
                    inspectThreadLocalAndContinue(switchedNestedPid)

            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.empty_waited == true
            assert responseBody.entrypoint_restored_waited == true
            assert responseBody.entrypoint_switched_waited == true
            assert responseBody.nested_switched_waited == true
            assert responseBody.entrypoint_trace_id != responseBody.nested_trace_id
            assert responseBody.entrypoint_span_id != responseBody.nested_span_id

            assertThreadContextMatchesStack(restoredEntrypointContext, responseBody, 'entrypoint')
            assertThreadContextMatchesStack(switchedEntrypointContext, responseBody, 'entrypoint')
            assertThreadContextMatchesStack(switchedNestedContext, responseBody, 'nested')
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(cleanupPid)
            }
        }
    }

    /**
     * Fiber switches store and restore DDTrace active stacks through the fiber
     * observer. This verifies OTel TLS follows the fiber's root, then the main root.
     */
    @Test
    void 'otel thread context follows fiber and main stack switches'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/fiber_switch.php')
        String cleanupPid = pausedRequest.pid
        boolean requestContinued = false

        try {
            Map<String, String> fiberContext = inspectThreadLocalAndContinue(pausedRequest.pid)

            String mainPid = waitForPausedPid(pausedRequest.responseFuture, 'main')
            cleanupPid = mainPid
            Map<String, String> mainContext = inspectThreadLocalAndContinue(mainPid)

            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.fiber_waited == true
            assert responseBody.main_waited == true
            assert responseBody.main_trace_id != responseBody.fiber_trace_id
            assert responseBody.main_span_id != responseBody.fiber_span_id

            assertThreadContextMatchesStack(fiberContext, responseBody, 'fiber')
            assertThreadContextMatchesStack(mainContext, responseBody, 'main')
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(cleanupPid)
            }
        }
    }

    /**
     * Runtime disable tears down tracing state and should null the TLS pointer.
     * Re-enabling in the same request should attach a fresh root context again.
     */
    @Test
    void 'otel thread context detaches when tracing is disabled and reattaches when reenabled'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/reenable.php')
        String cleanupPid = pausedRequest.pid
        boolean requestContinued = false

        try {
            Map<String, String> disabledContext = inspectThreadLocalAndContinue(pausedRequest.pid)
            assert disabledContext.ctx == '0x0'

            String reenabledPid = waitForPausedPid(pausedRequest.responseFuture, 'reenabled')
            cleanupPid = reenabledPid
            Map<String, String> reenabledContext = inspectThreadLocalAndContinue(reenabledPid)
            requestContinued = true
            HttpResponse<String> response = awaitResponse(pausedRequest)
            Map responseBody = parseJsonResponse(response)

            assert responseBody.disabled_waited == true
            assertThreadContextMatchesResponse(reenabledContext, responseBody)
        } finally {
            if (!requestContinued) {
                continuePausedRequestQuietly(cleanupPid)
            }
        }
    }

    /**
     * Process context is shared separately from per-thread TLS. This verifies the
     * process-level metadata and the advertised thread-local attribute key map.
     */
    @Test
    void 'otel process context shared memory has expected metadata and threadlocal attributes'() {
        PausedRequest pausedRequest = startPausedRequest('/otel_context/regular.php')
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
            assert processContext['threadlocal.attribute_key_map'] == THREADLOCAL_ATTRIBUTE_KEY_MAP
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
        assert threadContext['datadog.local_root_span_id'] == responseBody.local_root_span_id
        assert threadContext['service.name'] == responseBody.service_name
        assert threadContext['service.version'] == responseBody.service_version
        assert threadContext['deployment.environment.name'] == responseBody.deployment_environment_name
    }

    private static void assertThreadContextMatchesStack(
            Map<String, String> threadContext, Map responseBody, String stackPrefix) {
        assert threadContext.ctx != '0x0'
        assert threadContext.valid == '1'
        assert threadContext.attrs_data_size.toInteger() >= 18
        assert threadContext.trace_id == responseBody["${stackPrefix}_trace_id"]
        assert threadContext.span_id == responseBody["${stackPrefix}_span_id"]
        assert threadContext['datadog.local_root_span_id'] ==
                responseBody["${stackPrefix}_local_root_span_id"]
        assert threadContext['service.name'] == responseBody.service_name
        assert threadContext['service.version'] == responseBody.service_version
        assert threadContext['deployment.environment.name'] == responseBody.deployment_environment_name
    }

    private static Map parseJsonResponse(HttpResponse<String> response) {
        assert response.statusCode() == 200
        new JsonSlurper().parseText(response.body()) as Map
    }

    private static PausedRequest startPausedRequest(String path) {
        CONTAINER.execInContainer('rm', '-f', PID_FILE, PHASE_FILE)

        def request = CONTAINER.buildReq(path).GET().build()
        CompletableFuture<HttpResponse<String>> responseFuture =
                CONTAINER.httpClient.sendAsync(request, ofString())

        new PausedRequest(
                pid: waitForPausedPid(responseFuture),
                responseFuture: responseFuture)
    }

    private static String waitForPausedPid(
            CompletableFuture<HttpResponse<String>> responseFuture,
            String expectedPhase = null) {
        long deadline = System.currentTimeMillis() + 15_000

        while (System.currentTimeMillis() < deadline) {
            if (responseFuture.isDone()) {
                HttpResponse<String> response = responseFuture.getNow(null)
                throw new AssertionError(
                        "Request completed before the debugger pause: HTTP ${response.statusCode()}\n${response.body()}".toString())
            }

            ExecResult res = CONTAINER.execInContainer(
                    'bash', '-lc',
                    waitForPausedPidCommand(expectedPhase))
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

    private static String waitForPausedPidCommand(String expectedPhase) {
        if (expectedPhase == null) {
            return "test -s ${PID_FILE} && cat ${PID_FILE} || true".toString()
        }

        "test -s ${PID_FILE} && test -s ${PHASE_FILE} " +
                "&& test \"\$(cat ${PHASE_FILE})\" = '${expectedPhase}' " +
                "&& cat ${PID_FILE} || true"
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
