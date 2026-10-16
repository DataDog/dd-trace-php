package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.junit.jupiter.api.Assumptions.assumeTrue

/**
 * Shared AppSec tests for Symfony fixtures. Concrete subclasses supply the
 * {@code @Container} static field, declare {@code isExpectedVersion}, and
 * override the {@code supports*} / {@code expected*} hooks when the behaviour
 * diverges per Symfony major version (e.g. Symfony 4.x does not emit the
 * endpoints telemetry, nor the {@code http.route} tag).
 */
abstract class AbstractSymfonyAppsecTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    /** Whether this Symfony version exposes endpoints telemetry. */
    boolean supportsEndpointsCollection() { true }

    /** Whether SymfonyIntegration sets the {@code http.route} tag. */
    boolean supportsHttpRouteTag() { true }

    /** Whether a locale-aliased route (e.g. {@code /caminho-dinamico}) is wired up. */
    boolean supportsLocaleRoute() { true }

    /**
     * Expected total number of endpoints when {@link #supportsEndpointsCollection}
     * is true. Return a negative value to skip the exact-count assertion (useful
     * when a Symfony version exposes auxiliary routes we don't want to pin on).
     */
    int expectedEndpointCount() { -1 }

    /** Status code the fixture returns after a successful form_login. */
    int expectedLoginSuccessStatus() { 302 }

    /** Status code the fixture returns after a failed form_login. */
    int expectedLoginFailureStatus() { 302 }

    /** Status code the fixture returns after a successful signup. */
    int expectedSignupStatus() { 302 }

    /**
     * Routes that must appear in the endpoints telemetry. Entries are
     * {@code [path, method, resourceName]} triples; operation name is
     * always {@code http.request}.
     */
    List<List<String>> expectedEndpoints() { [] }

    @Test
    @Order(1)
    void 'Endpoints are not collected before the first request to framework'() {
        assumeTrue(supportsEndpointsCollection())
        HttpRequest req = container.buildReq('/outside_of_framework.php').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains('are_endpoints_collected: false')
        }
    }

    @Test
    @Order(2)
    void 'Endpoints are sent'() {
        assumeTrue(supportsEndpointsCollection())
        def trace = container.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        List<TelemetryHelpers.Endpoint> endpoints
        TelemetryHelpers.waitForAppEndpoints(container, 30, { List<TelemetryHelpers.Endpoint> messages ->
            endpoints = messages.collectMany { it.endpoints }
            endpoints.size() > 0
        })

        int expected = expectedEndpointCount()
        if (expected >= 0) {
            assert endpoints.size() == expected
        }
        expectedEndpoints().each { List<String> entry ->
            String path = entry[0]
            String method = entry[1]
            String resource = entry[2]
            assert endpoints.find {
                it.path == path && it.method == method &&
                        it.operationName == 'http.request' && it.resourceName == resource
            } != null, "Missing endpoint ${method} ${path}"
        }
    }

    // Not a @Test on purpose — pre-existing behaviour of Symfony62Tests. The
    // underlying tracer bookkeeping is per-request, so re-asserting the flag
    // on a subsequent request to outside_of_framework.php was unreliable.
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
    void 'login success automated event'() {
        // user `test-user@email.com` is seeded by docker-init.sh with password `test`
        String body = '_username=test-user%40email.com&_password=test'
        HttpRequest req = container.buildReq('/login')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofString(body)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == expectedLoginSuccessStatus()
        }
        Span span = trace.first()
        assert span.meta."usr.id" != ""
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(5)
    void 'login failure automated event'() {
        String body = '_username=aa&_password=ee'
        HttpRequest req = container.buildReq('/login')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofString(body)).build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == expectedLoginFailureStatus()
        }
        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == 'true'
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == 'identification'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert span.metrics._sampling_priority_v1 == 2.0d
        // SymfonyIntegration extracts the attempted username from the session;
        // `_username=aa` is persisted as `_security.last_username` before authenticate().
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'aa'
    }

    @Test
    @Order(6)
    void 'sign up automated event'() {
        String body = 'registration_form[email]=some@email.com' +
                '&registration_form[plainPassword]=somepassword' +
                '&registration_form[agreeTerms]=1'
        HttpRequest req = container.buildReq('/register')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofString(body)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == expectedSignupStatus()
        }
        Span span = trace.first()
        assert span.meta."usr.id" != ""
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == "identification"
        assert span.meta."appsec.events.users.signup.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(7)
    void 'test path params'() {
        HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('blocked')
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
        assert span.meta."appsec.blocked" == "true"
        if (supportsHttpRouteTag()) {
            assert span.meta."http.route" == '/dynamic-path/{param01}'
        }
    }

    @Test
    @Order(8)
    void 'http route for locale route'() {
        assumeTrue(supportsHttpRouteTag() && supportsLocaleRoute())
        HttpRequest req = container.buildReq('/caminho-dinamico/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."http.route" == '/caminho-dinamico/{param01}'
    }

    @Test
    @Order(9)
    void 'http route for utf8 route'() {
        assumeTrue(supportsHttpRouteTag())
        HttpRequest req = container.buildReq('/café/espresso').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."http.route" == '/café/{item}'
    }

    @Test
    @Order(10)
    void 'symfony http route disabled'() {
        assumeTrue(supportsHttpRouteTag())
        try {
            def res = container.execInContainer(
                    'bash', '-c',
                    '''echo export DD_TRACE_SYMFONY_HTTP_ROUTE=false >> /etc/apache2/envvars;
                   service apache2 restart''')
            assert res.exitCode == 0

            // Path params are still pushed to AppSec regardless of DD_TRACE_SYMFONY_HTTP_ROUTE,
            // so the WAF still blocks based on the path param key `param01`.
            HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 403
            }

            Span span = trace.first()
            assert span.meta."http.route" == null
            assert span.meta."symfony.route.name" != null
        } finally {
            def res = container.execInContainer(
                    'bash', '-c',
                    '''sed -i '/export DD_TRACE_SYMFONY_HTTP_ROUTE=/d' /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0
        }
    }
}
