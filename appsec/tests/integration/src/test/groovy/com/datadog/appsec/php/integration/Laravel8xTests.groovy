package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import com.datadog.appsec.php.TelemetryHelpers
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Laravel8xTests {
    static boolean expectedVersion = phpVersion.contains('7.4') && !variant.contains('zts')

    AppSecContainer getContainer() {
            getClass().CONTAINER
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'laravel8x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    @Order(2)
    void 'Login failure automated event'() {
        Trace trace = container.traceFromRequest('/authenticate?email=nonExisiting@email.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "false"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(3)
    void 'Login success automated event'() {
        //The user ciuser@example.com is already on the DB
        def trace = container.traceFromRequest('/authenticate?email=ciuser@example.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        //ciuser@example.com user id is 1
        Span span = trace.first()
        assert span.meta."usr.id" == "1"
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(4)
    void 'Sign up automated event'() {
        def trace = container.traceFromRequest(
                '/register?email=test-user-new@email.coms&name=somename&password=somepassword'
        ) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "2"
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == "identification"
        assert span.meta."appsec.events.users.signup.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(5)
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

    @Test
    @Order(1)
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

        def expectedEndpoints = 6

        if (endpoints.size() != expectedEndpoints) {
            println "Endpoints count mismatch (${endpoints.size()} != ${expectedEndpoints}). Endpoints:\n" +
                    endpoints.collect { e ->
                        "- method=${e.method}, path=${e.path}, operationName=${e.operationName}, resourceName=${e.resourceName}"
                    }.join("\n")
        }

        assert endpoints.size() == expectedEndpoints
        assert endpoints.find { it.path == '/' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /' } != null
        assert endpoints.find { it.path == 'authenticate' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET authenticate' } != null
        assert endpoints.find { it.path == 'register' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET register' } != null
        assert endpoints.find { it.path == 'dynamic-path/{param01}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET dynamic-path/{param01}' } != null
        assert endpoints.find { it.path == 'sanctum/csrf-cookie' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET sanctum/csrf-cookie' } != null
        assert endpoints.find { it.path == 'api/user' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET api/user' } != null
    }
}
