package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.MethodOrderer
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
    }

    @Test
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
    }

    @Test
    void 'symfony http route disabled'() {
        try {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''echo export DD_TRACE_SYMFONY_HTTP_ROUTE=false >> /etc/apache2/envvars;
                   service apache2 restart''')
            assert res.exitCode == 0

            HttpRequest req = container.buildReq('/dynamic-path/someValue').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
                assert re.statusCode() == 200
                assert re.body().contains('Hi someValue!')
            }

            Span span = trace.first()
            assert span.meta."http.route" != '/dynamic-path/{param01}'
        } finally {
            def res = CONTAINER.execInContainer(
                    'bash', '-c',
                    '''sed -i '/export DD_TRACE_SYMFONY_HTTP_ROUTE=/d' /etc/apache2/envvars;
                       service apache2 restart''')
            assert res.exitCode == 0
        }
    }
}
