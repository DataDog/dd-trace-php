package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.PhpFpm
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

@Testcontainers
@Slf4j
@DisabledIf('isZts')
class Apache2FpmTests implements CommonTests, SamplingTestsInFpm, EndpointFallbackSamplingTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'Pool environment'() {
        container.traceFromRequest('/poolenv.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text

            assert content.contains('Value of pool env is 10001')
        }
    }

    void setRateLimit(String limit) {
        new PhpFpm(container).restart([DD_APPSEC_TRACE_RATE_LIMIT: limit])
    }

    private void resetFpm() {
        new PhpFpm(container).restart()
    }

    @Test
    void 'resource renaming auto-enabled with appsec'() {
        // By default, DD_APPSEC_ENABLED=true is set but DD_TRACE_RESOURCE_RENAMING_ENABLED is not set.
        def req = container.buildReq('/hello.php').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        def span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d : "AppSec should be enabled"
        assert span.meta."http.endpoint" == '/hello.php' : "http.endpoint tag should be set when AppSec is enabled"
    }

    @Test
    void 'resource renaming disabled when explicitly set to false'() {
        // When DD_TRACE_RESOURCE_RENAMING_ENABLED=false is explicitly set, resource renaming should be disabled
        // even when AppSec is enabled
        disableEndpointRenaming()

        try {
            def req = container.buildReq('/hello.php').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }

            def span = trace.first()
            assert span.metrics."_dd.appsec.enabled" == 1.0d : "AppSec should still be enabled"
            assert span.meta."http.endpoint" == null : "http.endpoint tag should NOT be set when resource renaming is explicitly disabled"
        } finally {
            resetFpm()
        }
    }

    @Test
    void 'request blocked in request init is traced with auto-flush enabled'() {
        // The IP block happens while PHP is still in request startup. Closing
        // the root span there must not trigger an auto-flush, which would drop
        // the trace before AppSec's forced flush could send it.
        new PhpFpm(container).restart([DD_TRACE_AUTO_FLUSH_ENABLED: '1'])

        try {
            HttpRequest req = container.buildReq('/hello.php')
                    .header('Accept', 'application/json')
                    .header('X-Forwarded-For', '80.80.80.80').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.statusCode() == 403
                assert resp.body().contains("You've been blocked")
            }

            assert_blocked_span(trace.first())
        } finally {
            resetFpm()
        }
    }

    @Test
    void 'request running no PHP code is not traced with auto-flush enabled'() {
        // A missing script gets a 404 from FPM without running any PHP code,
        // so its trace is dropped. With auto-flush enabled, the spans of that
        // request must still be dropped at the end of the request rather than
        // sent or carried over to the next request. A single worker makes the
        // next request run in the same process.
        def fpm = new PhpFpm(container)
        fpm.backupPoolConfig()
        fpm.setPoolValue('pm.max_children', '1')
        fpm.restart([DD_TRACE_AUTO_FLUSH_ENABLED: '1'])

        try {
            HttpRequest req = container.buildReq('/does_not_exist.php').GET().build()
            HttpResponse<String> res = container.httpClient.send(req, ofString())
            assert res.statusCode() == 404

            // traceFromRequest fails if any other trace arrives first
            def trace = container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }
            assert trace.every { it.traceId == trace.traceId }
            assert trace.every { !(it.meta."http.url" ?: '').contains('does_not_exist') }

            Thread.sleep(1000)
            container.assertNoTraces()
        } finally {
            fpm.restorePoolConfig()
            resetFpm()
        }
    }

    @Test
    void 'test sampling priority'() {
        // Set rate limit to 5 to ensure fewer than 15 events get sampling priority 2
        setRateLimit('5')

        try {
            def results = (1..15).collect {
                def req = container.buildReq('/hello.php')
                        .header('User-Agent', "Arachni/v1")
                        .GET().build()

                def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                    resp.body() == 'Hello world!'
                }

                trace.first().metrics._sampling_priority_v1
            }.toList()

            System.out.println("Sampling priorities: ${results}")

            def countWithPriority2 = results.count { it == 2.0d }

            assert countWithPriority2 < 15 : "Expected fewer than 15 events with sampling priority 2, but got ${countWithPriority2}"

            assert countWithPriority2 > 0 : "Expected at least some events with sampling priority 2, but got ${countWithPriority2}"
        } finally {
            // Reset php-fpm to default configuration
            resetFpm()
        }
    }

}
