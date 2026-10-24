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
import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Covers Laminas MVC 3.3.x. 3.3 ships with a custom {@code /authenticate}
 * controller (two auth modes via {@code mode=a|b}) and {@code /behind-auth},
 * plus the full set of exotic routing types (Hostname, Method, Placeholder,
 * Regex, Scheme, Wildcard, Part/Chain). The newer skeleton drops both the
 * legacy routes and the exotic router types, so the 3.3-specific tests live
 * in this subclass rather than the abstract base.
 *
 * Laminas MVC 3.3.x supports PHP 7.3–8.1 per composer constraints in the shared framework.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class LaminasMvc33Tests extends AbstractLaminasAppsecTests {

    static boolean expectedVersion =
            ['7.3', '7.4', '8.0', '8.1'].contains(getPhpVersion()) && !getVariant().contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: getPhpVersion(),
                    phpVariant: getVariant(),
                    www: '../../../tests/Frameworks/Laminas/Mvc/Version_3_3',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Override
    boolean isAllowUnenumeratedRoutes() { false }

    @Override
    String getLoginAuthPath() { '/authenticate' }

    @Override
    String getBehindAuthPath() { '/behind-auth' }

    @Override
    List<List<String>> getExpectedEndpoints() {
        [
                ['/', '*', '* /'],
                ['/application[/:action]', '*', '* /application[/:action]'],
                ['/authenticate', '*', '* /authenticate'],
                ['/behind-auth', '*', '* /behind-auth'],
                ['/simple[/:key][/:pwd]', '*', '* /simple[/:key][/:pwd]'],
                ['/simple_view[/:key][/:pwd]', '*', '* /simple_view[/:key][/:pwd]'],
                ['/error[/:key][/:pwd]', '*', '* /error[/:key][/:pwd]'],
                ['/login/auth', '*', '* /login/auth'],
                ['/login/signup', '*', '* /login/signup'],
                ['/behind_auth', '*', '* /behind_auth'],
                ['/dynamic_route[/:param01[/static[/:param02]]]', '*',
                 '* /dynamic_route[/:param01[/static[/:param02]]]'],
                ['/dynamic-path[/:param01]', '*', '* /dynamic-path[/:param01]'],
                ['/resource', '*', '* /resource'],
                ['/resource/:resourceId', '*', '* /resource/:resourceId'],
                ['/resource/:resourceId/:subId', '*', '* /resource/:resourceId/:subId'],
                ['/chain/:chainId', '*', '* /chain/:chainId'],
                ['/verb-test', 'GET', 'GET /verb-test'],
                ['/verb-test', 'POST', 'POST /verb-test'],
                ['/verb-test', 'PUT', 'PUT /verb-test'],
                ['/verb-test', 'PATCH', 'PATCH /verb-test'],
                ['/verb-test', 'DELETE', 'DELETE /verb-test'],
                ['/multi-verb', 'GET', 'GET /multi-verb'],
                ['/multi-verb', 'HEAD', 'HEAD /multi-verb'],
                ['/multi-verb', 'OPTIONS', 'OPTIONS /multi-verb'],
                ['/multi-verb', 'POST', 'POST /multi-verb'],
                ['/multi-verb', 'PUT', 'PUT /multi-verb'],
                ['/profile', '*', '* /profile'],
                ['/regex-year/%year%', '*', '* /regex-year/%year%'],
                ['/scheme-only-page', '*', '* /scheme-only-page'],
                ['/placeholder-literal', '*', '* /placeholder-literal'],
                ['/wildcard-keys', '*', '* /wildcard-keys'],
                ['/wildcard-keys/*', '*', '* /wildcard-keys/*'],
                ['/any-verb', '*', '* /any-verb'],
        ]
    }

    @Test
    @Order(5)
    void 'Login success automated event - approach b'() {
        // The 3.3 LoginController supports two authentication modes
        // (mode=a passes the adapter inline; mode=b sets it on the service
        // and calls authenticate() without args). Both must emit the
        // automated login success event.
        def trace = container.traceFromRequest("${loginAuthPath}?email=ciuser@example.com&mode=b") {
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
        assert span.meta.'http.route' == '/dynamic-path[/:param01]'
    }

    @Test
    @Order(8)
    void 'nested Part and Chain routes produce correct http route'() {
        HttpRequest nestedReq = container.buildReq('/resource/42/99').GET().build()
        Trace nestedTrace = container.traceFromRequest(nestedReq, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert nestedTrace.first().meta.'http.route' == '/resource/:resourceId/:subId'

        HttpRequest chainReq = container.buildReq('/chain/abc').GET().build()
        Trace chainTrace = container.traceFromRequest(chainReq, ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert chainTrace.first().meta.'http.route' == '/chain/:chainId'
    }

    @Test
    @Order(9)
    void 'Route with no method constraint is reachable via GET POST and PUT'() {
        ["GET", "POST", "PUT"].each { verb ->
            HttpRequest req = container.buildReq('/any-verb')
                    .method(verb, HttpRequest.BodyPublishers.noBody()).build()
            Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.statusCode() == 200
            }
            assert trace.first().meta.'http.route' == '/any-verb'
        }
    }

    @Test
    @Order(10)
    void 'Regex Scheme Placeholder and Wildcard routes expose http route templates'() {
        Trace regexTrace = container.traceFromRequest(
                container.buildReq('/regex-year/2024').GET().build(),
                ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert regexTrace.first().meta.'http.route' == '/regex-year/%year%'

        Trace schemeTrace = container.traceFromRequest(
                container.buildReq('/scheme-only-page').GET().build(),
                ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert schemeTrace.first().meta.'http.route' == '/scheme-only-page'

        Trace placeholderTrace = container.traceFromRequest(
                container.buildReq('/placeholder-literal').GET().build(),
                ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert placeholderTrace.first().meta.'http.route' == '/placeholder-literal'

        Trace wildcardTrace = container.traceFromRequest(
                container.buildReq('/wildcard-keys/foo/bar').GET().build(),
                ofString()) { HttpResponse<String> resp ->
            assert resp.statusCode() == 200
        }
        assert wildcardTrace.first().meta.'http.route' == '/wildcard-keys/*'
    }
}
