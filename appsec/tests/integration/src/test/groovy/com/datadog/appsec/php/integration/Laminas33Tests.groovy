package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.io.InputStream
import java.net.http.HttpHeaders
import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofInputStream
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Laminas33Tests {

    /**
     * Laminas MVC 3.3.x supports PHP 7.3–8.1 per composer constraints in www/laminas33.
     */
    static boolean expectedVersion =
            ['7.3', '7.4', '8.0', '8.1'].contains(getPhpVersion()) && !getVariant().contains('zts')

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: getPhpVersion(),
                    phpVariant: getVariant(),
                    www: 'laminas33',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

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

        List<TelemetryHelpers.Endpoint> endpoints

        TelemetryHelpers.waitForAppEndpoints(container, 30, { List<TelemetryHelpers.Endpoint> messages ->
            endpoints = messages.collectMany { it.endpoints }
            endpoints.size() > 0
        })

        assert endpoints.size() == 5
        assert endpoints.find { it.path == '/' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /' } != null
        assert endpoints.find { it.path == '/authenticate' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /authenticate' } != null
        assert endpoints.find { it.path == '/behind-auth' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /behind-auth' } != null
        assert endpoints.find { it.path == '/register' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /register' } != null
        assert endpoints.find {
            it.path == '/dynamic-path[/:param01]' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /dynamic-path[/:param01]'
        } != null
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
        Trace trace = container.traceFromRequest('/authenticate?email=nonExisiting@email.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta.'appsec.events.users.login.failure.track' == 'true'
        assert span.meta.'_dd.appsec.events.users.login.failure.auto.mode' == 'identification'
        assert span.meta.'appsec.events.users.login.failure.usr.exists' == 'false'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(5)
    void 'Login success automated event'() {
        def trace = container.traceFromRequest('/authenticate?email=ciuser@example.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'usr.id' == '1'
        assert span.meta.'_dd.appsec.events.users.login.success.auto.mode' == 'identification'
        assert span.meta.'appsec.events.users.login.success.track' == 'true'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(6)
    void 'Authenticated user automated event after session login'() {
        HttpRequest loginReq = container.buildReq('/authenticate?email=ciuser@example.com').GET().build()
        HttpResponse<InputStream> loginResp = container.httpClient.send(loginReq, ofInputStream())
        assert loginResp.statusCode() == 200
        loginResp.body().close()

        String cookieHeader = loginResp.headers().allValues(HttpHeaders.SET_COOKIE)
                .collect { full -> full.split(';', 2)[0] }
                .join('; ')
        assert cookieHeader, 'login response should include session cookie'

        container.nextCapturedTrace()

        HttpRequest behindReq = container.buildReq('/behind-auth')
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
    }

    @Test
    @Order(7)
    void 'path params trigger WAF block and laminas http route template'() {
        HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().toLowerCase().contains('blocked')
        }

        Span span = trace.first()
        assert span.metrics.'_dd.appsec.enabled' == 1.0d
        assert span.metrics.'_dd.appsec.waf.duration' > 0.0d
        assert span.meta.'_dd.appsec.event_rules.version' != ''
        assert span.meta.'appsec.blocked' == 'true'
    }
}
