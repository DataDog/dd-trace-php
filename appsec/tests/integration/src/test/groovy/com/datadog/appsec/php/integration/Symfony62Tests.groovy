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

@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Symfony62Tests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.1') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Version_6_2',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // 21 routes come from the fixture controllers.
    @Override
    int expectedEndpointCount() { 21 }

    @Override
    List<List<String>> expectedEndpoints() {
        [
                ['/', 'GET', 'GET /'],
                ['/dynamic-path/{param01}', 'GET', 'GET /dynamic-path/{param01}'],
                ['/caminho-dinamico/{param01}', 'GET', 'GET /caminho-dinamico/{param01}'],
                ['/café/{item}', 'GET', 'GET /café/{item}'],
                ['/login', 'GET', 'GET /login'],
                ['/register', 'GET', 'GET /register'],
                ['/simple', 'GET', 'GET /simple'],
                ['/simple_view', 'GET', 'GET /simple_view'],
                ['/dynamic_route/{param01}/{param02}', 'GET', 'GET /dynamic_route/{param01}/{param02}'],
                ['/error', 'GET', 'GET /error'],
                ['/behind_auth', 'GET', 'GET /behind_auth'],
                ['/telemetry', 'GET', 'GET /telemetry'],
                ['/lucky/number', 'GET', 'GET /lucky/number'],
                ['/lucky/fail', 'GET', 'GET /lucky/fail'],
                ['/article/{slug}.{_format}', 'GET', 'GET /article/{slug}.{_format}'],
                ['/posts/{page}', 'GET', 'GET /posts/{page}'],
                ['/normalized/mixed/{id}.{_format}', 'GET', 'GET /normalized/mixed/{id}.{_format}'],
                ['/normalized/zero/{id}', 'GET', 'GET /normalized/zero/{id}'],
                ['/normalized/search.{_format}', 'GET', 'GET /normalized/search.{_format}'],
                ['/normalized/utf8/{föo}', 'GET', 'GET /normalized/utf8/{föo}'],
                ['/normalized/ambiguous/{slug}.{format}', 'GET', 'GET /normalized/ambiguous/{slug}.{format}'],
        ]
    }

    @Test
    @Order(7)
    @Override
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
    @Override
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
    @Override
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
    @Override
    void 'symfony http route disabled'() {
        try {
            def res = CONTAINER.execInContainer(
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
            assert span.meta."_dd.appsec.normalized_route" == null
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
    @Order(11)
    void 'normalized route is absent when API Security is disabled'() {
        try {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''echo export DD_API_SECURITY_ENABLED=false >> /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0

            Trace trace = container.traceFromRequest('/') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }

            Span span = trace.first()
            assert span.meta.'http.route' == '/'
            assert span.meta.'_dd.appsec.normalized_route' == null
        } finally {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''sed -i '/export DD_API_SECURITY_ENABLED=/d' /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0
        }
    }

    @Test
    @Order(12)
    void 'mixed dynamic values in one segment are combined'() {
        Trace trace = container.traceFromRequest('/normalized/mixed/article.json') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '/normalized/mixed/{id}.{_format}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized/mixed/{id+_format}'
    }

    @Test
    @Order(13)
    void 'zero-valued path parameter is retained'() {
        Trace trace = container.traceFromRequest('/normalized/zero/0') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '/normalized/zero/{id}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized/zero/{id}'
    }

    @Test
    @Order(14)
    void 'static part of a segment remains when its optional parameter is absent'() {
        Trace trace = container.traceFromRequest('/normalized/search') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '/normalized/search.{_format}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized/search'
    }

    @Test
    @Order(15)
    void 'UTF-8 optional parameter name is omitted when absent'() {
        Trace trace = container.traceFromRequest('/normalized/utf8') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '/normalized/utf8/{föo}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized/utf8'
    }

    @Test
    @Order(16)
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
    @Order(17)
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

    @Test
    @Order(18)
    void 'route requirements distinguish an absent defaulted mixed parameter'() {
        HttpRequest req = container.buildReq('/normalized/ambiguous/foo.bar').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body() == 'Ambiguous mixed route: foo.bar/html'
        }

        Span span = trace.first()
        assert span.meta.'http.route' ==
                '/normalized/ambiguous/{slug}.{format}'
        // Symfony matched the entire "foo.bar" value as slug and supplied
        // format from its default. URL-only inference ignores the framework
        // requirements and incorrectly treats "bar" as a matched format.
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/normalized/ambiguous/{slug}'
    }
}
