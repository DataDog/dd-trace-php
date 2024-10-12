package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isNotPhp83')
class SidecarFeaturesDisabledTests {
    static boolean isNotPhp83()  {
        !getPhpVersion().startsWith('8.3')
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            ) {
                @Override
                void configure() {
                    super.configure()
                    withEnv('DD_INSTRUMENTATION_TELEMETRY_ENABLED', 'false')
                    withEnv('DD_TRACE_SIDECAR_TRACE_SENDER', 'false')
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'appsec is enabled and sidecar is launched'() {
        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d

        ExecResult res = CONTAINER.execInContainer(
                '/bin/bash', '-ce', 'ps auxww; pgrep -f datadog-ipc-helper')
        if (res.exitCode != 0) {
            throw new AssertionError("Could not find helper: $res.stdout\n$res.stderr")
        }
    }
}
