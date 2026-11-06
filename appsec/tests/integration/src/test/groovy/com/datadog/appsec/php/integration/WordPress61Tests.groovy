package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.PhpFpm
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.MySQLContainer
import org.testcontainers.containers.Network
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

import java.net.http.HttpResponse

@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isExpectedVersion')
class WordPress61Tests extends AbstractWordPressAppsecTests {
    static boolean expectedVersion = phpVersion == '8.3' && !variant.contains('zts')

    private static Network network = Network.newNetwork()

    @Container
    private static MySQLContainer MYSQL = newMysqlContainer(network, '8.0', 'wp61')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/WordPress/Version_6_1',
            ).withNetwork(network).dependsOn(MYSQL) as AppSecContainer

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER, MYSQL)
    }

    @BeforeAll
    static void setup() {
        installWordPress(CONTAINER)

        // The normalized-route tests need consecutive requests to reach the
        // same worker, so that each one sees the route cache the previous
        // request populated. One worker makes that deterministic.
        PhpFpm fpm = new PhpFpm(CONTAINER)
        fpm.setPoolValue('pm.max_children', '1')
        fpm.reload()
    }

    // The route-normalization fixtures (mu-plugins and rewrite rules) are
    // installed only into the 6.1 tree by its docker-init.sh, so these tests
    // live here rather than in the shared base class.

    @Test
    @Order(6)
    void 'static prefix remains when optional rewrite capture is absent'() {
        Trace trace = CONTAINER.traceFromRequest('/normalized-cache/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()
        assert span.meta.'http.route' ==
                '^normalized-cache(?:/([^/]+))?/?$'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-cache'
    }

    @Test
    @Order(7)
    void 'optional rewrite capture is normalized for each request'() {
        Trace absentTrace = CONTAINER.traceFromRequest('/normalized-cache-shape/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span absentSpan = absentTrace.first()
        assert absentSpan.meta.'http.route' ==
                '^normalized-cache-shape/?([^/]*)/?$'
        assert absentSpan.meta.'_dd.appsec.normalized_route' ==
                '/normalized-cache-shape'

        Trace presentTrace = CONTAINER.traceFromRequest(
                '/normalized-cache-shape/present/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span presentSpan = presentTrace.first()
        assert presentSpan.meta.'http.route' ==
                '^normalized-cache-shape/?([^/]*)/?$'
        assert presentSpan.meta.'_dd.appsec.normalized_route' ==
                '/normalized-cache-shape/{param1}'
    }

    @Test
    @Order(8)
    void 'escaped regex literals remain static route text'() {
        Trace trace = CONTAINER.traceFromRequest('/normalized-literal/file.json/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' == '^normalized-literal/file\\.json$'
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/normalized-literal/file.json'
    }

    @Test
    @Order(9)
    void 'capture participation holes do not share a cached result shape'() {
        Trace absentTrace = CONTAINER.traceFromRequest(
                '/normalized-capture-hole/tail/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }

        Span absentSpan = absentTrace.first()
        assert absentSpan.meta.'http.route' ==
                '^normalized-capture-hole/(?:([^/]+)-)?([^/]+)$'
        assert absentSpan.meta.'_dd.appsec.normalized_route' ==
                '/normalized-capture-hole/{param2}'

        Trace presentTrace = CONTAINER.traceFromRequest(
                '/normalized-capture-hole/head-tail/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }

        Span presentSpan = presentTrace.first()
        assert presentSpan.meta.'http.route' ==
                '^normalized-capture-hole/(?:([^/]+)-)?([^/]+)$'
        // Both requests have capture 2 as their highest participating index,
        // but only this request includes capture 1. A highest-index cache key
        // serves the absent shape cached by the preceding request.
        assert presentSpan.meta.'_dd.appsec.normalized_route' ==
                '/normalized-capture-hole/{param1+param2}'
    }

    @Test
    @Order(10)
    void 'named regex captures are counted and retain their framework name'() {
        Trace trace = CONTAINER.traceFromRequest(
                '/normalized-named-captures/first-second/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta.'http.route' ==
                '^normalized-named-captures/(?P<first>[^/]+)-' +
                '(?P<second>[^/]+)/?$'
        // Both named captures share one URL segment and must be present in its
        // combined element. RFC-1103 does not define whether a route accepting
        // both terminal-slash forms should retain '/', so accept either form.
        String normalizedRoute = span.meta.'_dd.appsec.normalized_route'
        assert normalizedRoute ==
                '/normalized-named-captures/{first+second}' ||
                normalizedRoute ==
                '/normalized-named-captures/{first+second}/'
    }

    @Test
    @Order(11)
    void 'optional leading rewrite capture matched against the URL path'() {
        Trace trace = CONTAINER.traceFromRequest(
                '/lead/normalized-pathinfo/tail/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
                assert response.body().text == 'lead'
        }

        Span span = trace.first()
        assert span.meta.'http.route' ==
                '(?:([^/]+)/)?normalized-pathinfo/([^/]+)/?$'
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/{param1}/normalized-pathinfo/{param2}'
    }

    @Test
    @Order(12)
    void 'normalized route is absent when WordPress matched a PATH_INFO request'() {
        // Apache passes PATH_INFO=/normalized-pathinfo/tail; WordPress keeps
        // it in WP::$request but, after the anchor rule, matches later rules
        // against 'index.php/normalized-pathinfo/tail'. Capture 1 took
        // 'index.php' (the response body), so replaying the rule on
        // WP::$request would report it absent.
        Trace trace = CONTAINER.traceFromRequest(
                '/index.php/normalized-pathinfo/tail/') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
                assert response.body().text == 'index.php'
        }

        Span span = trace.first()
        assert span.meta.'http.route' ==
                '(?:([^/]+)/)?normalized-pathinfo/([^/]+)/?$'
        assert span.meta.'_dd.appsec.normalized_route' == null
    }

    @Test
    @Order(13)
    void 'normalized route is absent when API Security is disabled'() {
        PhpFpm fpm = new PhpFpm(CONTAINER)
        try {
            fpm.restart(['DD_API_SECURITY_ENABLED': 'false'])

            Trace trace = CONTAINER.traceFromRequest('/normalized-cache/') {
                HttpResponse<InputStream> response ->
                    assert response.statusCode() == 200
            }

            Span span = trace.first()
            assert span.meta.'http.route' ==
                    '^normalized-cache(?:/([^/]+))?/?$'
            assert span.meta.'_dd.appsec.normalized_route' == null
        } finally {
            fpm.restart()
        }
    }
}
