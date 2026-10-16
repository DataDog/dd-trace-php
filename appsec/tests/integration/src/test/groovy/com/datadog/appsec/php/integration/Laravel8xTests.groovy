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
    static boolean expectedVersion = phpVersion.contains('8.1') && !variant.contains('zts')

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
                    www: '../../../tests/Frameworks/Laravel/Version_8_x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
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
        // Existing user (id=1) with a wrong password: the Failed event carries the
        // resolved user object, so usr.id and usr.exists must be populated.
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
        //The user ciuser@example.com is already on the DB
        def trace = container.traceFromRequest('/login/auth?email=ciuser@example.com') {
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
    @Order(8)
    void 'Sign up automated event'() {
        def trace = container.traceFromRequest(
                '/login/signup?email=test-user-new@email.coms&name=somename&password=somepassword'
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
        // Laravel uri() returns the route without a leading slash
        assert span.meta."http.route" == 'dynamic-path/{param01}'
        // Normalizer adds the leading slash and keeps {param01} as-is
        assert span.meta."_dd.appsec.normalized_route" == '/dynamic-path/{param01}'
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

        assert endpoints.size() == 30
        assert endpoints.find { it.path == '/' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET /' } != null
        assert endpoints.find { it.path == 'login/auth' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET login/auth' } != null
        assert endpoints.find { it.path == 'login/signup' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET login/signup' } != null
        assert endpoints.find { it.path == 'dynamic-path/{param01}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET dynamic-path/{param01}' } != null
        assert endpoints.find { it.path == 'api/user' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET api/user' } != null
        assert endpoints.find { it.path == 'normalized-optional/{value?}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET normalized-optional/{value?}' } != null
        assert endpoints.find { it.path == 'normalized-default/{format?}' && it.method == 'GET' && it.operationName == 'http.request' && it.resourceName == 'GET normalized-default/{format?}' } != null
        assert endpoints.find {
            it.path == 'normalized-ambiguous/{name}.{ext?}' && it.method == 'GET' &&
                    it.operationName == 'http.request' &&
                    it.resourceName == 'GET normalized-ambiguous/{name}.{ext?}'
        } != null
    }

    @Test
    @Order(10)
    void 'optional param present produces correct normalized route'() {
        HttpRequest req = container.buildReq('/normalized-optional/hello').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'hello'
        }

        Span span = trace.first()
        assert span.meta.'http.route' == 'normalized-optional/{value?}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-optional/{value}'
    }

    @Test
    @Order(11)
    void 'optional param absent produces correct normalized route'() {
        HttpRequest req = container.buildReq('/normalized-optional').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'absent'
        }

        Span span = trace.first()
        assert span.meta.'http.route' == 'normalized-optional/{value?}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-optional'
    }

    @Test
    @Order(12)
    void 'defaulted optional absent from URL produces normalized route without the param'() {
        // The route uses ->defaults('format', 'html'). When the URL has no {format?} segment,
        // Laravel injects 'html' into $route->parameters() — but the param is absent from the URL.
        // The normalized route must not include {format} in this case.
        HttpRequest req = container.buildReq('/normalized-default').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'html'
        }

        Span span = trace.first()
        assert span.meta.'http.route' == 'normalized-default/{format?}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-default'
    }

    @Test
    @Order(13)
    void 'route requirements distinguish an absent defaulted mixed parameter'() {
        HttpRequest req = container.buildReq('/normalized-ambiguous/report.txt').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'report.txt/html'
        }

        Span span = trace.first()
        assert span.meta.'http.route' ==
                'normalized-ambiguous/{name}.{ext?}'
        // Laravel matched all of "report.txt" as name because ext only accepts
        // pdf or json, then supplied the default ext. The integration ignores
        // those requirements and infers ext participation from the dot alone.
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/normalized-ambiguous/{name}'
    }

    @Test
    @Order(14)
    void 'normalized route is absent when API Security is disabled'() {
        try {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''echo export DD_API_SECURITY_ENABLED=false >> /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0

            HttpRequest req = container.buildReq('/normalized-optional/hello').GET().build()
            Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
            }

            Span span = trace.first()
            assert span.meta.'http.route' == 'normalized-optional/{value?}'
            assert span.meta.'_dd.appsec.normalized_route' == null
        } finally {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''sed -i '/export DD_API_SECURITY_ENABLED=/d' /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0
        }
    }
}
