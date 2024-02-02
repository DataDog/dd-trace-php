package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@EnabledIf('isExpectedVersion')
class Laravel8xTests {
    static boolean expectedVersion = phpVersion.contains('7.4') && !variant.contains('zts')

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
                    www: 'laravel8x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'Login failure automated event'() {
        Trace trace = container.traceFromRequest('/authenticate?email=nonExisiting@email.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 403
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "safe"
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "false"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    void 'Login success automated event'() {
        //The user ciuser@example.com is already on the DB
        def trace = container.traceFromRequest('/authenticate?email=ciuser@example.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        //ciuser@example.com user id is 1
        Span span = trace.first()
        assert span.meta."usr.id" == "1"
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == "safe"
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }


    @Test
    void 'Sign up automated event'() {
        def trace = container.traceFromRequest(
                '/register?email=test-user-new@email.coms&name=somename&password=somepassword'
        ) { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "2"
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == "safe"
        assert span.meta."appsec.events.users.signup.track" == "true"
        assert span.metrics._sampling_priority_v1 == 2.0d
    }
}
