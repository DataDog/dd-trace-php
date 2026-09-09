package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.PhpFpm
import org.junit.jupiter.api.Assumptions
import org.junit.jupiter.api.Test

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion

trait EndpointFallbackSamplingTests extends SamplingTestsInFpm {

    /**
     * Test Requirement 1: If http.route is present, use it for sampling
     * Expected: Schema sampling should work using http.route
     */
    @Test
    void 'sampling uses http route when present'() {
        def trace = container.traceFromRequest('/endpoint_fallback.php?case=with_route') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."http.route" == "/users/{id}/profile"
        assert trace.first().meta."_dd.appsec.s.res.body" != null // we sampled

        trace = container.traceFromRequest('/endpoint_fallback.php?case=with_route') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."_dd.appsec.s.res.body" == null // we did not sample again
    }

    /**
     * Test Requirement 2a: If http.route is absent and http.endpoint is present (non-404),
     * use http.endpoint for sampling
     * Expected: Schema sampling should work using http.endpoint
     */
    @Test
    void 'sampling uses http endpoint when http route absent and status is not 404'() {
        def trace = container.traceFromRequest('/endpoint_fallback.php?case=with_endpoint') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null

        assert trace.first().meta."http.route" == null
        assert trace.first().meta."http.endpoint" == "/api/products/{param:int}"
        assert trace.first().meta."_dd.appsec.s.res.body" != null // sampling happened

        trace = container.traceFromRequest('/endpoint_fallback.php?case=with_endpoint') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }
        assert trace != null
        assert trace.first().meta."_dd.appsec.s.res.body" == null // no sampling again
    }

    /**
     * Test Requirement 2b: If http.route is absent and http.endpoint is present but status is 404,
     * should NOT sample (failsafe)
     * Expected: No schema sampling should occur
     */
    @Test
    void 'sampling does not use http endpoint when status is 404'() {
        def trace = container.traceFromRequest('/endpoint_fallback.php?case=404') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 404
        }
        assert trace != null
        assert trace.first().meta."http.route" == null
        assert trace.first().meta."http.endpoint" == "/api/notfound/{param:int}"
        assert trace.first().meta."_dd.appsec.s.res.body" == null // we did not sample
    }

    /**
     * Test Requirement 3: If neither http.route nor http.endpoint is present,
     * compute http.endpoint on-demand and use for sampling, but do NOT set it on the span
     * Expected: Schema sampling should work, but http.endpoint should not be in meta
     */
    @Test
    void 'sampling computes endpoint on-demand when neither route nor endpoint present'() {
        disableEndpointRenaming()

        try {
            def trace = container.traceFromRequest('/endpoint_fallback.php?case=computed') {
                HttpResponse<InputStream> resp ->
                    assert resp.statusCode() == 200
            }
            assert trace != null

            assert trace.first().meta."http.url" != null
            assert trace.first().meta."http.url".contains("/endpoint_fallback_computed/users/123/orders/456")
            assert trace.first().meta."http.route" == null
            assert trace.first().meta."http.endpoint" == null
            assert trace.first().meta."_dd.appsec.s.res.body" != null // we did sample

            trace = container.traceFromRequest('/endpoint_fallback.php?case=computed') {
                HttpResponse<InputStream> resp ->
                    assert resp.statusCode() == 200
            }
            assert trace != null
            assert trace.first().meta."_dd.appsec.s.res.body" == null
            assert trace.first().meta."http.endpoint" == null
        } finally {
            resetFpm()
        }
    }

    /**
     * If neither http.route, nor http.endpoint, nor http.url is available,
     * there is nothing to key the sampling on: the request must not be sampled
     * and it must be counted as RFC-1012's appsec.api_security.missing_route.
     *
     * The other two API security metrics, appsec.api_security.request.schema
     * and .request.no_schema, are asserted here as well, as this test can
     * produce them in passing. Draining telemetry means waiting on the metric
     * interval (hardcoded to 10s), so it is paid on a single PHP version; the
     * behaviour under test does not depend on the version.
     */
    @Test
    void 'no sampling without a route, and api security telemetry metrics'() {
        Assumptions.assumeTrue(phpVersion == '8.3',
                'Draining telemetry is slow; only done on one PHP version')

        // with renaming enabled, http.endpoint would be set to "/" on span close.
        disableEndpointRenaming()

        try {
            def trace = container.traceFromRequest('/endpoint_fallback.php?case=missing_route') {
                HttpResponse<InputStream> resp ->
                    assert resp.statusCode() == 200
            }
            assert trace != null

            assert trace.first().meta."http.route" == null
            assert trace.first().meta."http.endpoint" == null
            assert trace.first().meta."http.url" == null
            assert trace.first().meta."_dd.appsec.s.res.body" == null // we did not sample

            // a route not sampled by any other test: the first request has schemas
            // extracted, the second one is suppressed by the sampler
            def route = '/api_security/telemetry'
            2.times {
                container.traceFromRequest("/api_security.php?route=$route") {
                    HttpResponse<InputStream> resp ->
                        assert resp.statusCode() == 200
                }
            }

            TelemetryHelpers.Metric schema
            TelemetryHelpers.Metric noSchema
            TelemetryHelpers.Metric missingRoute

            TelemetryHelpers.waitForMetrics(container, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
                def allSeries = messages.collectMany { it.series }
                schema = schema ?: allSeries.find {
                    it.name == 'api_security.request.schema'
                }
                noSchema = noSchema ?: allSeries.find {
                    it.name == 'api_security.request.no_schema'
                }
                missingRoute = missingRoute ?: allSeries.find {
                    it.name == 'api_security.missing_route'
                }
                schema && noSchema && missingRoute
            }

            // counts are not exact: the requests waitForMetrics itself makes in
            // order to flush the metrics are evaluated for API security too
            [schema: schema, noSchema: noSchema, missingRoute: missingRoute].each {
                name, metric ->
                    assert metric != null : "api_security metric $name not found"
                    assert metric.namespace == 'appsec'
                    assert metric.type == 'count'
                    assert metric.points[0][1] >= 1.0
                    assert 'framework:unknown' in metric.tags
            }
        } finally {
            resetFpm()
        }
    }

    private AppSecContainer getContainer() {
        getClass().container
    }

    void disableEndpointRenaming() {
        new PhpFpm(container).restart([DD_TRACE_RESOURCE_RENAMING_ENABLED: 'false'])
    }
}
