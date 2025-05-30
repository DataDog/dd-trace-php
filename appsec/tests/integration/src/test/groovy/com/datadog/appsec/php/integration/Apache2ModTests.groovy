package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Trace
import com.datadog.appsec.php.model.Span
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.condition.DisabledIf
import org.junit.jupiter.api.Test
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.io.InputStream
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import static java.net.http.HttpResponse.BodyHandlers.ofString

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getTracerVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult


@Testcontainers
@Slf4j
class Apache2ModTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
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

    @Test
    void 'Set authorization header'() {
        HttpRequest req = container.buildReq('/hello.php')
                .header('authorization', 'digest 1234567890')
                .GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        Span span = trace.first()
        assert span.meta['http.request.headers.authorization'] == 'digest 1234567890'
    }

    @Test
    void 'trace without attack after soft restart'() {
        ExecResult res = CONTAINER.execInContainer('service', 'apache2', 'reload')
        if (res.exitCode != 0) {
            throw new AssertionError("Failed reloading apache2: $res.stderr")
        }
        log.info "Result of restart: STDOUT: $res.stdout , STDERR: $res.stderr"

        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> it ->
            assert it.body().text == 'Hello world!'
        }
        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d
    }
}
