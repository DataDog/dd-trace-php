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
class Laravel8xTests extends AbstractLaravelAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.1') && !variant.contains('zts')

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

    // The 8.x fixture ships ~30 routes (feature-controller suite); the listed
    // entries below are the subset exercised by AppSec. Extras are accepted
    // via the base-class default (`isAllowUnenumeratedRoutes = true`).
    @Override
    List<List<String>> getExpectedEndpoints() {
        [
                ['/', 'GET', 'GET /'],
                ['login/auth', 'GET', 'GET login/auth'],
                ['login/signup', 'GET', 'GET login/signup'],
                ['dynamic-path/{param01}', 'GET', 'GET dynamic-path/{param01}'],
                ['api/user', 'GET', 'GET api/user'],
                ['normalized-optional/{value?}', 'GET', 'GET normalized-optional/{value?}'],
                ['normalized-default/{format?}', 'GET', 'GET normalized-default/{format?}'],
                ['normalized-ambiguous/{name}.{ext?}', 'GET', 'GET normalized-ambiguous/{name}.{ext?}'],
                ['normalized-no-replay/{format?}', 'GET', 'GET normalized-no-replay/{format?}'],
        ]
    }

    @Test
    @Order(9)
    @Override
    void 'test path params'() {
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
        // pdf or json, then supplied the default ext. The integration takes
        // participation from Laravel's own capture map, where ext is absent.
        assert span.meta.'_dd.appsec.normalized_route' ==
                '/normalized-ambiguous/{name}'
    }

    @Test
    @Order(14)
    void 'does not fetch the compiled route again to normalize optionals'() {
        HttpRequest req = container.buildReq('/normalized-no-replay').GET().build()
        Trace trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 200
            // Laravel itself fetches the compiled route twice while matching.
            // Normalization must reuse the binder result instead of adding a third call.
            assert re.body() == '2'
        }

        Span span = trace.first()
        assert span.meta.'http.route' == 'normalized-no-replay/{format?}'
        assert span.meta.'_dd.appsec.normalized_route' == '/normalized-no-replay'
    }

    @Test
    @Order(15)
    void 'normalized route is produced from a cached route collection'() {
        // route:cache makes Laravel match with CompiledRouteCollection, which
        // looks the route up through Symfony's compiled matcher and only then
        // binds it. The capture map must still come from that bind.
        try {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    'cd /var/www && DD_TRACE_CLI_ENABLED=false php artisan route:cache')
            assert res.exitCode == 0 : res.stderr

            // Cached routes are rebuilt as plain Route objects, so the probe
            // route no longer counts getCompiled() calls: this shows the
            // request was matched against the cached collection.
            Trace trace = container.traceFromRequest(
                    container.buildReq('/normalized-no-replay').GET().build(),
                    ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body() == '0'
            }
            assert trace.first().meta.'http.route' == 'normalized-no-replay/{format?}'
            assert trace.first().meta.'_dd.appsec.normalized_route' == '/normalized-no-replay'

            trace = container.traceFromRequest(
                    container.buildReq('/normalized-optional/hello').GET().build(),
                    ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body() == 'hello'
            }
            assert trace.first().meta.'_dd.appsec.normalized_route' == '/normalized-optional/{value}'

            trace = container.traceFromRequest(
                    container.buildReq('/normalized-default').GET().build(),
                    ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body() == 'html'
            }
            assert trace.first().meta.'_dd.appsec.normalized_route' == '/normalized-default'

            trace = container.traceFromRequest(
                    container.buildReq('/normalized-ambiguous/report.txt').GET().build(),
                    ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body() == 'report.txt/html'
            }
            assert trace.first().meta.'_dd.appsec.normalized_route' == '/normalized-ambiguous/{name}'
        } finally {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    'cd /var/www && DD_TRACE_CLI_ENABLED=false php artisan route:clear')
            assert res.exitCode == 0
        }
    }

    @Test
    @Order(16)
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
