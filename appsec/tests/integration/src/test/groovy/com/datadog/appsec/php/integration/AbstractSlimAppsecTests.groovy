package com.datadog.appsec.php.integration

import com.datadog.appsec.php.model.Span
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * Shared AppSec tests for the Slim fixtures under {@code tests/Frameworks/Slim}.
 * Concrete subclasses supply the {@code @Container} static field and the
 * {@code expectedVersion} flag, and override {@link #getExpectedEndpoints} to
 * enumerate every route their fixture ships. Endpoint-collection tests come
 * from {@link AbstractFrameworkAppsecTests}.
 */
abstract class AbstractSlimAppsecTests extends AbstractFrameworkAppsecTests {

    /**
     * The WAF rule {@code path-params-tests} (src/test/waf/recommended.json)
     * blocks any request whose path parameters include a parameter *named*
     * {@code param01}, whatever its value.
     */
    @Test
    @Order(7)
    void 'path params trigger WAF block'() {
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
        // The raw Slim route pattern; normalization is a separate piece of work.
        assert span.meta.'http.route' == '/dynamic-path/{param01}'
    }

    @Test
    @Order(8)
    void 'optional path params are sent to the WAF and trigger a block'() {
        HttpRequest req = container.buildReq('/dynamic_route/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().toLowerCase().contains('blocked')
        }

        Span span = trace.first()
        assert span.meta.'appsec.blocked' == 'true'
        assert span.meta.'http.route' == '/dynamic_route/{param01}[/static/{param02}]'
    }

    @Test
    @Order(9)
    void 'path params that match no rule are not blocked'() {
        HttpRequest req = container.buildReq('/parameterized/someValue').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            assert re.body().contains('Hello, someValue')
        }

        Span span = trace.first()
        assert span.metrics.'_dd.appsec.enabled' == 1.0d
        assert span.meta.'appsec.blocked' == null
        assert span.meta.'http.route' == '/parameterized/{value}'
    }

    @Test
    @Order(10)
    void 'static route is not blocked'() {
        HttpRequest req = container.buildReq('/simple').GET().build()
        container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
        }
    }
}
