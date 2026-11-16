package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
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
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isNotPhp83')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
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
                    // these force sidecar to run
                    withEnv('DD_INSTRUMENTATION_TELEMETRY_ENABLED', 'false')
                    withEnv('DD_TRACE_SIDECAR_TRACE_SENDER', 'false')
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    @Order(1)
    void 'appsec is enabled and sidecar is launched'() {
        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d

        ExecResult res = CONTAINER.execInContainer(
                '/bin/bash', '-ce', 'ps auxww; pgrep -f [d]atadog-ipc-helper')
        if (res.exitCode != 0) {
            throw new AssertionError("Could not find helper: $res.stdout\n$res.stderr")
        }
    }

    @Order(2)
    @Test
    void 'appsec is disabled and sidecar is not launched'() {
        ExecResult res = CONTAINER.execInContainer(
                'sed', '-i', 's/^datadog.appsec.enabled.*$/datadog.appsec.enabled=false/', '/etc/php/php.ini')
        assert res.exitCode == 0

        res = CONTAINER.execInContainer(
                '/bin/bash', '-c', '''
                    pid=`pgrep -f [d]atadog-ipc-helper`;
                    if [ -n "$pid" ]; then echo "Helper is running: $pid";
                    kill -9 $pid; fi''')
        assert res.exitCode == 0

        res = CONTAINER.execInContainer('service', 'apache2', 'restart')
        assert res.exitCode == 0

        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        assert !trace.first().metrics.containsKey('_dd.appsec.enabled')

        res = CONTAINER.execInContainer(
                '/bin/bash', '-ce', 'ps auxww; ! pgrep -f [d]atadog-ipc-helper')
        if (res.exitCode != 0) {
            throw new AssertionError("Found helper when not expected: $res.stdout\n$res.stderr")
        }
    }

    @Order(3)
    @Test
    void 'sidecar is enabled but not appsec'() {
        ExecResult res = CONTAINER.execInContainer(
                'sed', '-i', 's/^datadog.appsec.enabled.*$/datadog.appsec.enabled=false/', '/etc/php/php.ini')
        assert res.exitCode == 0

        res = CONTAINER.execInContainer(
                '/bin/bash', '-c', '''
                    pid=`pgrep -f [d]atadog-ipc-helper`;
                    if [ -n "$pid" ]; then echo "Helper is running: $pid";
                    kill -9 $pid; fi''')
        assert res.exitCode == 0

        res = CONTAINER.execInContainer('/bin/bash', '-c',
            'echo DD_INSTRUMENTATION_TELEMETRY_ENABLED=true >> /etc/apache2/envvars; service apache2 restart')
        assert res.exitCode == 0

        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            resp.body() == 'Hello world!'
        }

        assert !trace.first().metrics.containsKey('_dd.appsec.enabled')

        res = CONTAINER.execInContainer(
                '/bin/bash', '-ce', 'ps auxww; pgrep -f [d]atadog-ipc-helper')
        if (res.exitCode != 0) {
            throw new AssertionError("Could not find helper: $res.stdout\n$res.stderr")
        }
    }
}
