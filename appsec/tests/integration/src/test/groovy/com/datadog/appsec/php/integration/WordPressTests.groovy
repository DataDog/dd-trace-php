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
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers
import org.testcontainers.utility.DockerImageName

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isExpectedVersion')
class WordPressTests {
    static boolean expectedVersion = phpVersion == '8.3' && !variant.contains('zts')

    private static Network network = Network.newNetwork()

    @Container
    private static MySQLContainer MYSQL = new MySQLContainer(
            DockerImageName.parse("${System.getProperty('DOCKER_MIRROR') ?: 'docker.io'}/library/mysql:8.0")
                    .asCompatibleSubstituteFor('mysql'))
            .withDatabaseName('wordpress')
            .withUsername('root')
            .withPassword('test')
            .withNetwork(network)
            .withNetworkAliases('mysql')
            .waitingFor(Wait.forLogMessage(".*ready for connections.*", 1)) as MySQLContainer

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'wordpress',
            ).withNetwork(network) as AppSecContainer

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER, MYSQL)
    }

    @BeforeAll
    static void installWordPress() {
        def mysqlInfo = MYSQL.containerInfo
        def mysqlIp = mysqlInfo.networkSettings.networks.values().find {
            it.aliases?.contains('mysql')
        }?.ipAddress

        assert mysqlIp != null : "Could not determine MySQL container IP"
        log.info("MySQL IP: {}", mysqlIp)

        def res = CONTAINER.execInContainer('bash', '-c',
                "sed -i \"s/'mysql'/'${mysqlIp}'/\" /var/www/public/wp-config.php")
        assert res.exitCode == 0 : "Failed to update wp-config.php: ${res.stderr}"

        // Run wp-cli with tracing and AppSec disabled so these CLI processes
        // don't interfere with the FPM workers' sidecar/AppSec helper. FPM
        // keeps running throughout so the already-started AppSec helper
        // (launched by the container's initial FPM workers) remains active.
        res = CONTAINER.execInContainer('bash', '-c',
                """export DD_TRACE_CLI_ENABLED=false DD_APPSEC_ENABLED=0
                   wp core install \\
                       --path=/var/www/public \\
                       --url=http://localhost \\
                       --title='Test Site' \\
                       --admin_user=admin \\
                       --admin_password=admin \\
                       --admin_email=admin@test.com \\
                       --allow-root \\
                       --skip-email""")
        log.info("wp core install stdout: {}", res.stdout)
        log.info("wp core install stderr: {}", res.stderr)
        assert res.exitCode == 0 : "WordPress install failed: ${res.stderr}"

        def port = CONTAINER.firstMappedPort
        res = CONTAINER.execInContainer('bash', '-c',
                """export DD_TRACE_CLI_ENABLED=false DD_APPSEC_ENABLED=0
                   wp option update siteurl 'http://localhost:${port}' --path=/var/www/public --allow-root
                   wp option update home 'http://localhost:${port}' --path=/var/www/public --allow-root
                   wp rewrite structure '/%postname%/' --path=/var/www/public --allow-root
                   wp rewrite flush --hard --path=/var/www/public --allow-root""")
        assert res.exitCode == 0 : "Failed to update WordPress URLs: ${res.stderr}"

        PhpFpm fpm = new PhpFpm(CONTAINER)
        fpm.setPoolValue('pm.max_children', '1')
        fpm.reload()

        CONTAINER.clearTraces()
    }

    @Test
    @Order(1)
    void 'Login success automated event'() {
        // admin/admin is the user created by `wp core install` in @BeforeAll.
        Trace trace = CONTAINER.traceFromRequest('/login_trigger.php?user=admin&pass=admin') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.success.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.success.auto.mode" == "identification"
        assert span.meta."usr.id" == "1"
        assert span.meta."appsec.events.users.login.success.usr.login" == 'admin'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(2)
    void 'Login failure automated event - wrong password for existing user'() {
        // Real failure path: the user exists, but the password is wrong. The
        // integration must call track_user_login_failure_event_automated with a
        // populated usr.login and usr.exists=true. missing_user_login must NOT
        // fire because the login is present.
        Trace trace = CONTAINER.traceFromRequest('/login_trigger.php?user=admin&pass=wrong') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'admin'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "true"
        assert !span.meta.containsKey("appsec.events.users.login.failure.usr.id")
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(3)
    void 'Login failure automated event - unknown user'() {
        // Non-empty unknown username: the integration emits a failure event with
        // usr.exists=false. Login is non-empty, so no missing_user_login metric.
        Trace trace = CONTAINER.traceFromRequest('/login_trigger.php?user=ghost&pass=whatever') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.login.failure.track" == "true"
        assert span.meta."_dd.appsec.events.users.login.failure.auto.mode" == "identification"
        assert span.meta."appsec.events.users.login.failure.usr.login" == 'ghost'
        assert span.meta."appsec.events.users.login.failure.usr.exists" == "false"
        assert !span.meta.containsKey("appsec.events.users.login.failure.usr.id")
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(4)
    void 'Sign up automated event'() {
        // Direct call to register_new_user via signup_trigger.php; this exercises
        // the integration's hook on the function call, regardless of multisite or
        // email-related side effects of the standard registration form.
        Trace trace = CONTAINER.traceFromRequest(
                '/signup_trigger.php?login=newuser1&email=newuser1@example.com') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."appsec.events.users.signup.track" == "true"
        assert span.meta."_dd.appsec.events.users.signup.auto.mode" == "identification"
        assert span.meta."appsec.events.users.signup.usr.login" == 'newuser1'
        assert span.metrics._sampling_priority_v1 == 2.0d
    }

    @Test
    @Order(5)
    void 'Authenticated user automated event'() {
        // behind_auth_trigger.php sets the current user and exercises
        // wp_validate_auth_cookie, which the integration hooks to emit
        // track_authenticated_user_event_automated.
        Trace trace = CONTAINER.traceFromRequest('/behind_auth_trigger.php') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "1"
        assert span.meta."_dd.appsec.usr.id" == "1"
        assert span.meta."_dd.appsec.user.collection_mode" == "identification"
    }

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
