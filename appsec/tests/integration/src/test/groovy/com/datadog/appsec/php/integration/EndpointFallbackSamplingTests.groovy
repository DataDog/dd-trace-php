package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import org.junit.jupiter.api.Test

import java.net.http.HttpResponse

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

    private AppSecContainer getContainer() {
        getClass().container
    }

    void disableEndpointRenaming() {
        flushProfilingData()
        def res = container.execInContainer(
                'bash', '-c',
                '''kill -9 `pgrep php-fpm`;
               export DD_TRACE_RESOURCE_RENAMING_ENABLED=false;
               php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini''')
        assert res.exitCode == 0
    }
}
