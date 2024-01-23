package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Mapper
import com.datadog.appsec.php.model.Span
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.wait.strategy.HostPortWaitStrategy
import org.testcontainers.containers.wait.strategy.WaitStrategy
import org.testcontainers.containers.wait.strategy.WaitStrategyTarget
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static java.time.temporal.ChronoUnit.SECONDS

@Testcontainers
@EnabledIf('isExpectedVersion')
class RoadRunnerTests {
    static boolean expectedVersion = phpVersionAtLeast('7.4') && !variant.contains('zts')

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'roadrunner',
            ).with {
                // we only start listening on http after run.sh has finished
                setWaitStrategy(new WaitStrategy() {
                    @Override
                    void waitUntilReady(WaitStrategyTarget waitStrategyTarget) {
                        // we're good. Allow run.sh to run
                    }

                    @Override
                    WaitStrategy withStartupTimeout(Duration startupTimeout) {
                        this
                    }
                })
                it
            }

    @BeforeAll
    static void beforeAll() {
        new HostPortWaitStrategy().withStartupTimeout(Duration.of(300, SECONDS) ).waitUntilReady(CONTAINER)
    }

    @Test
    void 'produces two traces for two requests'() {
        def trace1 = CONTAINER.traceFromRequest('/') { HttpResponse<InputStream> it ->
            assert it.statusCode() == 200
            assert it.headers().firstValue('Content-type').get() == 'text/plain'
            assert it.body().text == 'Hello world!'
        }
        def trace2 = CONTAINER.traceFromRequest('/')
        assert trace1.size() == 1
        assert trace2.size() == 1

        assert trace1[0].meta['component'] == 'roadrunner'
        assert trace1[0].meta['http.client_ip'] instanceof String
        assert trace1[0].meta['_dd.appsec.event_rules.version'] =~ /\d+\.\d+\.\d+/
        assert trace1[0].metrics['_dd.appsec.enabled'] == 1.0d
        assert trace1[0].meta['http.status_code'] == '200'

        assert trace2[0].meta['http.status_code'] == '200'
    }

    @Test
    void 'blocking json on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('"title": "You\'ve been blocked"')
            assert re.statusCode() == 403
            assert re.headers().firstValue('Content-type').get() == 'application/json'
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
        assert span.meta."_dd.appsec.json" != null
        assert span.meta.'http.status_code' == '403'
        def triggers = Mapper.INSTANCE.readerFor(Map).readValue(span.meta."_dd.appsec.json")
        assert triggers['triggers'][0]['rule']['name'] == 'Block IP Addresses'
    }

    @Test
    void 'blocking html on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.80')
                .header('Accept', 'text/html')
                .GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.body().contains('<title>You\'ve been blocked</title>')
            assert re.statusCode() == 403
            assert re.headers().firstValue('Content-type').get().contains('text/html')
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking forward on request start'() {
        HttpRequest req = CONTAINER.buildReq('/')
                .header('X-Forwarded-For', '80.80.80.81').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 303
            assert re.headers().firstValue('Location').get() == 'datadoghq.com'
        }

        Span span = trace.first()
        assert span.meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with html response'() {
        HttpRequest req = CONTAINER.buildReq('/?user=user2020')
                .header('Accept', 'text/html').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking user with redirect'() {
        HttpRequest req = CONTAINER.buildReq('/?user=user2023').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.statusCode() == 303
            assert it.headers().firstValue('Location').get() == 'datadoghq.com'
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }

    @Test
    void 'blocking on response with html'() {
        HttpRequest req = CONTAINER.buildReq('/?status=418')
                .header('Accept', 'text/html').GET().build()
        def trace = CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> it ->
            assert it.body().contains('<title>You\'ve been blocked</title>')
            assert it.statusCode() == 403
            assert it.headers().firstValue('Content-type').get().contains('text/html')
        }
        assert trace.first().meta."appsec.blocked" == "true"
    }
}
