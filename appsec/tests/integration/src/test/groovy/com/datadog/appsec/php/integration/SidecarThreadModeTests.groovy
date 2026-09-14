package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import org.junit.jupiter.api.Test
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Exercises AppSec with the sidecar hosted by an in-process master-listener
 * thread. This must remain a separate container from the default-mode suites:
 * the connection mode is read during PHP MINIT and cannot be changed safely
 * after the web server has started.
 */
@Testcontainers
class SidecarThreadModeTests {
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
                    withEnv('DD_TRACE_SIDECAR_CONNECTION_MODE', 'thread')
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'appsec blocks requests in forced sidecar thread mode'() {
        HttpRequest request = CONTAINER.buildReq('/phpinfo.php')
                .header('Content-type', 'application/json')
                .header('Accept', 'application/json')
                .header('X-Forwarded-For', '80.80.80.80')
                .GET()
                .build()

        def trace = CONTAINER.traceFromRequest(request, ofString()) {
            HttpResponse<String> response ->
                assert response.statusCode() == 403
        }

        def span = trace.first()
        assert span.metrics.'_dd.appsec.enabled' == 1.0d
        assert span.metrics.'_dd.appsec.waf.duration' > 0.0d
        assert span.meta.'appsec.blocked' == 'true'
        assert span.meta.'_dd.appsec.json'
    }
}
