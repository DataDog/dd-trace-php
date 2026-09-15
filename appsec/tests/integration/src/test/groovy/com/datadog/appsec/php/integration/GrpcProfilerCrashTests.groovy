package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.PhpFpm
import com.datadog.appsec.php.mock_grpc.MockGrpcServer
import groovy.json.JsonSlurper
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.BindMode
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration
import java.util.concurrent.CompletableFuture
import java.util.concurrent.TimeUnit

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Reproduces profiler runtime-cache use-after-free when PHP executes on
 * short-lived native gRPC threads under NTS.
 */
@Testcontainers
@EnabledIf('isPhp85Nts')
class GrpcProfilerCrashTests {
    private static final int IDLE_SECONDS = 25

    static boolean isPhp85Nts() {
        phpVersion == '8.5' && variant == 'release'
    }

    public static final MockGrpcServer MOCK_GRPC_SERVER = new MockGrpcServer()

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'grpc_profiler',
                    needsGrpc: true,
                    needsProfiler: true,
            ) {
                {
                    dependsOn MOCK_GRPC_SERVER
                }

                @Override
                void configure() {
                    super.configure()
                    withEnv('DD_APPSEC_ENABLED', '0')
                    withEnv('DD_TRACE_ENABLED', '0')
                    withEnv('DD_TRACE_SIDECAR_TRACE_SENDER', '0')
                    withEnv('DD_PROFILING_ENABLED', '1')
                    withEnv('DD_PROFILING_ALLOCATION_ENABLED', '0')
                    org.testcontainers.Testcontainers.exposeHostPorts(
                            MOCK_GRPC_SERVER.port)
                    withEnv(
                            'GRPC_TEST_TARGET',
                            'host.testcontainers.internal:' +
                                    MOCK_GRPC_SERVER.port)
                    withFileSystemBind(
                            MOCK_GRPC_SERVER.certificateFile.absolutePath,
                            '/tmp/grpc-server.crt',
                            BindMode.READ_ONLY)
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'profiled gRPC callbacks survive idle worker interval'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        waitForWorkers(fpm, 1)

        Map config = configuration()
        int workerPid = (Integer) config.worker_pid
        assert fpm.workerPids() == [workerPid.toString()]
        assert config.php_version.startsWith('8.5.')
        assert !config.php_zts
        assert config.grpc_version == '1.83.1'
        assert config.profiler_version
        assert config.profiler_enabled == '1'
        assert config.allocation_enabled == '0'
        assert config.appsec_enabled == '0'
        assert config.trace_enabled == '0'
        assert !config.trace_sender
        assert config.fork_support == '0'

        int requestsBefore = MOCK_GRPC_SERVER.requestCount
        int metadataBefore = MOCK_GRPC_SERVER.requestsWithPluginMetadata
        CompletableFuture<HttpResponse<String>> future = sendCrashProbe()

        HttpResponse<String> response
        Throwable requestFailure = null
        try {
            response = future.get(IDLE_SECONDS + 30, TimeUnit.SECONDS)
        } catch (Throwable failure) {
            requestFailure = failure
        }

        if (waitForWorkerReplacement(fpm, workerPid, 5_000)) {
            AssertionError error = new AssertionError(
                    (Object) ('FPM worker ' + workerPid +
                            ' crashed during the second profiled gRPC callback'))
            if (requestFailure != null) {
                error.initCause(requestFailure)
            }
            throw error
        }
        if (requestFailure != null) {
            throw requestFailure
        }

        assert response.statusCode() == 200: response.body()
        assert response.body(): 'profiler probe returned an empty body'
        Map result = (Map) new JsonSlurper().parseText(response.body())
        assert result.worker_pid == workerPid
        assert result.grpc_version == '1.83.1'
        assert result.before.completed
        assert result.after.completed
        assert result.idle_seconds == IDLE_SECONDS
        assert MOCK_GRPC_SERVER.requestCount - requestsBefore == 2
        assert MOCK_GRPC_SERVER.requestsWithPluginMetadata - metadataBefore == 2
    }

    private static Map configuration() {
        HttpRequest request = CONTAINER.buildReq(
                '/grpc_profiler.php?mode=config')
                .timeout(Duration.ofSeconds(30))
                .GET()
                .build()
        HttpResponse<String> response = CONTAINER.httpClient.send(
                request,
                ofString())
        assert response.statusCode() == 200: response.body()
        assert response.body(): 'profiler configuration returned an empty body'
        (Map) new JsonSlurper().parseText(response.body())
    }

    private static CompletableFuture<HttpResponse<String>> sendCrashProbe() {
        HttpRequest request = CONTAINER.buildReq('/grpc_profiler.php')
                .timeout(Duration.ofSeconds(IDLE_SECONDS + 20))
                .GET()
                .build()
        CONTAINER.httpClient.sendAsync(request, ofString())
    }

    private static boolean waitForWorkerReplacement(
            PhpFpm fpm,
            int workerPid,
            long timeoutMillis) {
        long deadline = System.currentTimeMillis() + timeoutMillis
        while (System.currentTimeMillis() < deadline) {
            if (fpm.workerPids() != [workerPid.toString()]) {
                return true
            }
            Thread.sleep(100)
        }
        false
    }

    private static void waitForWorkers(PhpFpm fpm, int expected) {
        long deadline = System.currentTimeMillis() + 15_000
        while (System.currentTimeMillis() < deadline) {
            if (fpm.workerPids().size() == expected) {
                return
            }
            Thread.sleep(100)
        }
        throw new AssertionError(
                'FPM did not reach ' + expected +
                        ' workers; got ' + fpm.workerPids())
    }
}
