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

/**
 * Shared AppSec tests for Laravel 8.x and newer. Concrete subclasses supply
 * the {@code @Container} static field, declare {@code isExpectedVersion}, and
 * override the {@code expected*} hooks when response codes or endpoint
 * telemetry diverge per Laravel major version. Laravel 4.x/5.x are covered by
 * the tracer suite only; adding AppSec coverage for them would require a
 * Guard-based event hook that this suite does not model.
 */
abstract class AbstractLaravelAppsecTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    /** Expected total endpoints count; return negative to skip exact check. */
    int expectedEndpointCount() { -1 }

    /**
     * Status code the fixture's /login/signup endpoint returns.
     * Laravel 8.x returns 200 explicitly; 9.x+ scaffolded `register()` redirects
     * (`return redirect('/simple')`) which produces 302.
     */
    int expectedSignupStatus() { 200 }

    /** List of {@code [path, method, resourceName]} entries that must be present. */
    List<List<String>> expectedEndpoints() { [] }

    /**
     * Routes that must appear in the endpoints telemetry. Entries are
     * {@code [path, method, resourceName]} triples; operation name is
     * always {@code http.request}.
     */
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

    // Not a @Test on purpose — pre-existing behaviour of Laravel8xTests. The
    // tracer bookkeeping is per-request, so re-asserting the flag on a
    // subsequent request to outside_of_framework.php was unreliable.
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
        Trace trace = container.traceFromRequest('/login/auth?email=nonExisiting@email.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "false"
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'nonExisiting@email.com'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(5)
    void 'Login failure automated event - wrong password for existing user'() {
        // Existing user (id=1) with a wrong password: the Failed event carries
        // the resolved user object, so usr.id and usr.exists must be populated.
        Trace trace = container.traceFromRequest('/login/auth?email=ciuser@example.com&password=wrong') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "true"
        assert span.meta."appsec.events.users.login.failure.usr.id" == "1"
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'ciuser@example.com'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(6)
    void 'Login failure automated event - missing login triggers telemetry'() {
        // Empty email: Auth::attempt(['email' => '']) fails with no user, the
        // Laravel integration calls track_user_login_failure_event_automated('',
        // null, false, [], 'laravel'). Per spec both missing_user_login and
        // missing_user_id must fire, tagged framework:laravel.
        container.traceFromRequest('/login/auth?email=') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        TelemetryHelpers.Metric missingUserLogin
        TelemetryHelpers.Metric missingUserId
        TelemetryHelpers.waitForMetrics(container, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            missingUserLogin = allSeries.find {
                it.name == 'appsec.instrum.user_auth.missing_user_login' &&
                        'event_type:login_failure' in it.tags &&
                        'framework:laravel' in it.tags
            }
            missingUserId = allSeries.find {
                it.name == 'appsec.instrum.user_auth.missing_user_id' &&
                        'event_type:login_failure' in it.tags &&
                        'framework:laravel' in it.tags
            }
            missingUserLogin != null && missingUserId != null
        }
        assert missingUserLogin != null
        assert missingUserLogin.namespace == 'appsec'
        assert missingUserLogin.points[0][1] >= 1.0
        assert missingUserLogin.type == 'count'

        assert missingUserId != null
        assert missingUserId.namespace == 'appsec'
        assert missingUserId.points[0][1] >= 1.0
        assert missingUserId.type == 'count'
    }

    @Test
    @Order(7)
    void 'Login success automated event'() {
        // The user ciuser@example.com is seeded by docker-init.sh (id=1)
        def trace = container.traceFromRequest('/login/auth?email=ciuser@example.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "1"
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(8)
    void 'Sign up automated event'() {
        def trace = container.traceFromRequest(
                '/login/signup?email=test-user-new@email.coms&name=somename&password=somepassword'
        ) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == expectedSignupStatus()
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "2"
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == "identification"
        assert span.meta."appsec.events.users.signup.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(9)
    void 'test path params'() {
        // Set ip which is blocked
        HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('Sorry, you cannot access this page. Please contact the customer service team.')
            assert re.body().contains('Security provided by Datadog')
            assert !re.body().contains('Server Error')
        }

        Span span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d
        assert span.metrics."_dd.appsec.waf.duration" > 0.0d
        assert span.meta."_dd.appsec.event_rules.version" != ''
        assert span.meta."appsec.blocked" == "true"
    }
}
