package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Test
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

    private static <T> List<T> waitForTelemetryData(int timeoutSec, Closure<Boolean> cl, Class<T> cls) {
        List<T> messages = []
        def deadline = System.currentTimeSeconds() + timeoutSec
        def lastHttpReq = System.currentTimeSeconds() - 6
        while (System.currentTimeSeconds() < deadline) {
            if (System.currentTimeSeconds() - lastHttpReq > 5) {
                lastHttpReq = System.currentTimeSeconds()
                // used to flush global (not request-bound) telemetry metrics
                def request = CONTAINER.buildReq('/').GET().build()
                def trace = CONTAINER.traceFromRequest(request, ofString()) { HttpResponse<String> resp ->
                    assert resp.body().size() > 0
                }
            }
            def telData = CONTAINER.drainTelemetry(500)
            messages.addAll(
                    TelemetryHelpers.filterMessages(telData, cls))
            if (cl.call(messages)) {
                break
            }
        }
        messages
    }

    private static List<TelemetryHelpers.AppEndpoints> waitForMetrics(int timeoutSec, Closure<Boolean> cl) {
        waitForTelemetryData(timeoutSec, cl, TelemetryHelpers.AppEndpoints)
    }

    @Test
    void 'Endpoints are sended'() {
        def trace = container.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        assert trace.traceId != null

        List<TelemetryHelpers.Endpoint> endpoints

        waitForMetrics(30) { List<TelemetryHelpers.AppEndpoints> messages ->
            endpoints = messages.collectMany { it.endpoints }
            endpoints.size() > 0
        }

        assert endpoints.size() == 6
        assert endpoints.find { it.path == '/' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /' } != null
        assert endpoints.find { it.path == 'authenticate' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET authenticate' } != null
        assert endpoints.find { it.path == 'register' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET register' } != null
        assert endpoints.find { it.path == 'dynamic-path/{param01}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET dynamic-path/{param01}' } != null
        assert endpoints.find { it.path == 'sanctum/csrf-cookie' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET sanctum/csrf-cookie' } != null
        assert endpoints.find { it.path == 'api/user' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET api/user' } != null
    }
}
