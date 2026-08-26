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
class Slim48Tests {
    static boolean expectedVersion = phpVersion == '8.1' && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'slim48',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    @Order(1)
    void 'route lookup cache does not suppress a present optional parameter'() {
        Trace absentTrace = CONTAINER.traceFromRequest('/normalized-optional') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span absentSpan = absentTrace.first()
        assert absentSpan.meta.'http.route' == '/normalized-optional[/{value}]'
        assert absentSpan.meta.'_dd.appsec.normalized_route' == '/normalized-optional'

        Trace presentTrace = CONTAINER.traceFromRequest('/normalized-optional/present') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span presentSpan = presentTrace.first()
        assert presentSpan.meta.'http.route' == '/normalized-optional[/{value}]'
        assert presentSpan.meta.'_dd.appsec.normalized_route' ==
                '/normalized-optional/{value}'
    }

    @Test
    @Order(2)
    void 'absent static-only optional suffix is not emitted'() {
        Trace trace = CONTAINER.traceFromRequest('/normalized-static') {
            HttpResponse<InputStream> response ->
                assert response.statusCode() == 200
        }
        Span span = trace.first()

        assert span.meta.'http.route' == '/normalized-static[.json]'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-static'
    }
}
