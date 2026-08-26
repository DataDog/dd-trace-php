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

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class CodeIgniter22Tests {
    static boolean expectedVersion = phpVersion == '7.4' && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'codeigniter22',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    @Order(1)
    void 'multiple captures in one segment are combined'() {
        Trace trace = CONTAINER.traceFromRequest('/archive/2026-08') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == 'archive/([0-9]{4})-([0-9]{2})'
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/archive/{param1+param2}'
    }

    @Test
    @Order(2)
    void 'implicit route does not normalize the concrete request URI as static'() {
        Trace trace = CONTAINER.traceFromRequest('/normalized/item/123') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == 'normalized/item/123'
        String normalizedRoute = span.meta.'_dd.appsec.normalized_route'
        // RFC-1103 permits either omission when no accurate route is available or
        // the generic /{param1} catch-all fallback, but never the concrete URI.
        assert normalizedRoute == null || normalizedRoute == '/{param1}'
    }

    @Test
    @Order(3)
    void 'literal dot in an exact route remains static'() {
        Trace trace = CONTAINER.traceFromRequest('/releases/v1.0') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == 'releases/v1.0'
        assert span.meta.'_dd.appsec.normalized_route' == '/releases/v1.0'
    }

    @Test
    @Order(4)
    void 'optional regex path segment is omitted when absent'() {
        Trace trace = CONTAINER.traceFromRequest('/articles') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == 'articles(?:/([0-9]+))?'
        assert span.meta.'_dd.appsec.normalized_route' == '/articles'
    }

    @Test
    @Order(5)
    void 'optional regex path segment is emitted when present'() {
        Trace trace = CONTAINER.traceFromRequest('/articles/42') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == 'articles(?:/([0-9]+))?'
        assert span.meta.'_dd.appsec.normalized_route' == '/articles/{param1}'
    }
}
