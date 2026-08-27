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
class Symfony62Tests {
    static boolean expectedVersion = phpVersion.contains('8.1') && !variant.contains('zts')

    AppSecContainer getContainer() {
            getClass().CONTAINER
    }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'symfony62',
            )

    @Test
    @Order(4)
    void 'login success automated event'() {
        //The user ciuser@example.com is already on the DB
        String body = '_username=test-user%40email.com&_password=test'
        HttpRequest req = container.buildReq('/login')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofString(body)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 302
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
            assert resp.statusCode() == 302
        }
        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == 'true'
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == 'identification'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == 'false'
        assert span.metrics._sampling_priority_v1 == 2.0d
        // The Symfony integration extracts the attempted username from the session;
        // '_username=aa' is stored as _security.last_username before authenticate() is called.
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'aa'
    }

    @Test
    @Order(6)
    void 'sign up automated event'() {
        String body = 'registration_form[email]=some@email.com&registration_form[plainPassword]=somepassword&registration_form[agreeTerms]=1'
        HttpRequest req = container.buildReq('/register')
                .header('Content-Type', 'application/x-www-form-urlencoded')
                .POST(HttpRequest.BodyPublishers.ofString(body)).build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 302
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
        assert span.meta."http.route" == '/dynamic-path/{param01}'
        assert span.meta."_dd.appsec.normalized_route" == '/dynamic-path/{param01}'
    }

    @Test
    @Order(8)
    void 'http route for locale route'() {
        HttpRequest req = container.buildReq('/caminho-dinamico/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('blocked')
        }

        Span span = trace.first()
        assert span.meta."http.route" == '/caminho-dinamico/{param01}'
        assert span.meta."_dd.appsec.normalized_route" == '/caminho-dinamico/{param01}'
    }

    @Test
    @Order(9)
    void 'http route for utf8 route'() {
        HttpRequest req = container.buildReq('/café/espresso').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."http.route" == '/café/{item}'
        // Static segment 'café' is percent-encoded per RFC 3986; é (U+00E9) → %C3%A9
        assert span.meta."_dd.appsec.normalized_route" == '/caf%C3%A9/{item}'
    }

    @Test
    @Order(10)
    void 'symfony http route disabled'() {
        try {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''echo export DD_TRACE_SYMFONY_HTTP_ROUTE=false >> /etc/apache2/envvars;
                   service apache2 restart''')
            assert res.exitCode == 0

            // path params are always pushed to AppSec regardless of DD_TRACE_SYMFONY_HTTP_ROUTE,
            // so the WAF still blocks based on the path param key 'param01'
            HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 403
            }

            Span span = trace.first()
            assert span.meta."http.route" == null
            assert span.meta."symfony.route.name" != null
            assert span.resource == 'app_home_dynamic'
        } finally {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''sed -i '/export DD_TRACE_SYMFONY_HTTP_ROUTE=/d' /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0
        }
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
    @Order(3)
    void 'Endpoints are collected after the first request to framework'() {
        HttpRequest req = container.buildReq('/outside_of_framework.php').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains('are_endpoints_collected: true')
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

        assert endpoints.size() == 9
        assert endpoints.find { it.path == '/' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /' } != null
        assert endpoints.find { it.path == '/dynamic-path/{param01}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /dynamic-path/{param01}' } != null
        assert endpoints.find { it.path == '/login' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /login' } != null
        assert endpoints.find { it.path == '/_error/{code}.{_format}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /_error/{code}.{_format}' } != null
        assert endpoints.find { it.path == '/register' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /register' } != null
        assert endpoints.find { it.path == '/caminho-dinamico/{param01}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /caminho-dinamico/{param01}' } != null
        assert endpoints.find { it.path == '/article/{slug}.{_format}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /article/{slug}.{_format}' } != null
        assert endpoints.find { it.path == '/café/{item}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /café/{item}' } != null
        assert endpoints.find { it.path == '/posts/{page}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /posts/{page}' } != null
    }

    @Test
    @Order(11)
    void 'optional param absent: cache key does not bleed into present case'() {
        // Hit /posts (page absent from URL — uses default=1) first so that if the cache key
        // were just the route name, the result '/posts' would be stored and served for /posts/2.
        HttpRequest absentReq = container.buildReq('/posts').GET().build()
        Trace absentTrace = container.traceFromRequest(absentReq, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }
        assert absentTrace.first().meta.'http.route' == '/posts/{page}'
        assert absentTrace.first().meta.'_dd.appsec.normalized_route' == '/posts'

        // Now hit /posts/2 (page present in URL). With a coarse cache key (route name only)
        // this would incorrectly return '/posts' from cache instead of '/posts/{page}'.
        HttpRequest presentReq = container.buildReq('/posts/2').GET().build()
        Trace presentTrace = container.traceFromRequest(presentReq, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }
        assert presentTrace.first().meta.'http.route' == '/posts/{page}'
        assert presentTrace.first().meta.'_dd.appsec.normalized_route' == '/posts/{page}'
    }

    @Test
    @Order(12)
    void 'mixed segment route normalizes both params into one brace group'() {
        HttpRequest req = container.buildReq('/article/my-post.html').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'my-post.html'
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '/article/{slug}.{_format}'
        assert span.meta.'_dd.appsec.normalized_route' == '/article/{slug+_format}'
    }
}
