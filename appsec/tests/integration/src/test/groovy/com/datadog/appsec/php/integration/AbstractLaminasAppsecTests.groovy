package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static java.net.http.HttpResponse.BodyHandlers.ofInputStream
import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Shared AppSec tests for the Laminas MVC fixtures. Concrete subclasses supply
 * the {@code @Container} static field, declare {@code isExpectedVersion}, and
 * override the {@code is*} / {@code get*} hooks when a specific MVC version
 * exposes different route literals or a reduced endpoint set.
 *
 * Laminas 3.3 exposes a legacy {@code /authenticate} + {@code /behind-auth}
 * controller pair; newer fixtures standardise on {@code /login/auth} +
 * {@code /behind_auth}. The hooks absorb that divergence so the auth test
 * bodies remain shared.
 */
abstract class AbstractLaminasAppsecTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    /**
     * When {@code true} (default), {@link #getExpectedEndpoints} may be a
     * strict subset of the endpoints emitted by the fixture (extras are
     * accepted). Set to {@code false} in subclasses that enumerate every
     * expected route to also assert the exact total count.
     */
    boolean isAllowUnenumeratedRoutes() { true }

    /**
     * Routes that must appear in the endpoints telemetry. Entries are
     * {@code [path, method, resourceName]} triples; operation name is always
     * {@code http.request}. Must be exhaustive when
     * {@link #isAllowUnenumeratedRoutes} is {@code false}.
     */
    List<List<String>> getExpectedEndpoints() { [] }

    /**
     * Path prefix for the login authentication endpoint (no query string).
     * Legacy 3.3 fixtures use {@code /authenticate}; newer fixtures use
     * {@code /login/auth}.
     */
    String getLoginAuthPath() { '/login/auth' }

    /**
     * Path for the behind-auth endpoint. 3.3 fixtures expose {@code /behind-auth}
     * (dash); newer fixtures expose {@code /behind_auth} (underscore).
     */
    String getBehindAuthPath() { '/behind_auth' }

    /**
     * Expected {@code http.route} tag value for login auth responses. Mirrors
     * {@link #getLoginAuthPath} by default; subclasses override only if the
     * literal differs from the request path.
     */
    String getLoginAuthHttpRoute() { getLoginAuthPath() }

    /**
     * Expected {@code http.route} tag value for behind-auth responses.
     */
    String getBehindAuthHttpRoute() { getBehindAuthPath() }

    @Test
    @Order(1)
    void 'Endpoints are not collected before the first request to framework'() {
        HttpRequest req = container.buildReq('/outside_of_framework.php').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains('are_endpoints_collected: false')
        }
    }

    @Test
    @Order(2)
    void 'Endpoints are sent'() {
        Trace trace = container.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert trace.traceId != null
        assert trace.first().meta.'http.route' == '/'

        List<TelemetryHelpers.Endpoint> endpoints
        TelemetryHelpers.waitForAppEndpoints(container, 30, { List<TelemetryHelpers.Endpoint> messages ->
            endpoints = messages.collectMany { it.endpoints }
            endpoints.size() > 0
        })

        if (!allowUnenumeratedRoutes) {
            assert endpoints.size() == expectedEndpoints.size()
        }
        expectedEndpoints.each { List<String> entry ->
            String path = entry[0]
            String method = entry[1]
            String resource = entry[2]
            assert endpoints.find {
                it.path == path && it.method == method &&
                        it.operationName == 'http.request' && it.resourceName == resource
            } != null, "Missing endpoint ${method} ${path}"
        }
    }

    @Test
    @Order(3)
    void 'Endpoints are collected after the first request to framework'() {
        HttpRequest req = container.buildReq('/outside_of_framework.php').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains('are_endpoints_collected: true')
        }
    }

    @Test
    @Order(4)
    void 'Login failure automated event'() {
        Trace trace = container.traceFromRequest("${loginAuthPath}?email=nonExisiting@email.com") {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta.'appsec.events.users.login.failure.track' == 'true'
        assert span.meta.'_dd.appsec.events.users.login.failure.auto.mode' == 'identification'
        assert span.meta.'appsec.events.users.login.failure.usr.exists' == 'false'
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta.'http.route' == loginAuthHttpRoute
    }

    @Test
    @Order(5)
    void 'Login success automated event'() {
        def trace = container.traceFromRequest("${loginAuthPath}?email=ciuser@example.com") {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'usr.id' == '1'
        assert span.meta.'_dd.appsec.events.users.login.success.auto.mode' == 'identification'
        assert span.meta.'_dd.appsec.usr.login' == 'ciuser@example.com'
        assert span.meta.'appsec.events.users.login.success.track' == 'true'
        assert span.metrics._sampling_priority_v1 == 2.0d
        assert span.meta.'http.route' == loginAuthHttpRoute
    }

    @Test
    @Order(6)
    void 'Authenticated user automated event after session login'() {
        HttpRequest loginReq = container.buildReq("${loginAuthPath}?email=ciuser@example.com").GET().build()
        HttpResponse<InputStream> loginResp = container.httpClient.send(loginReq, ofInputStream())
        assert loginResp.statusCode() == 200
        loginResp.body().close()

        String cookieHeader = loginResp.headers().allValues('Set-Cookie')
                .collect { full -> full.split(';', 2)[0] }
                .join('; ')
        assert cookieHeader, 'login response should include session cookie'

        container.nextCapturedTrace()

        HttpRequest behindReq = container.buildReq(behindAuthPath)
                .header('Cookie', cookieHeader)
                .GET()
                .build()
        Trace trace = container.traceFromRequest(behindReq, ofInputStream()) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'usr.id' == '1'
        assert span.meta.'_dd.appsec.usr.id' == '1'
        assert span.meta.'_dd.appsec.user.collection_mode' == 'identification'
        assert span.meta.'http.route' == behindAuthHttpRoute
    }
}
