package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.model.Span
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.testcontainers.containers.MySQLContainer
import org.testcontainers.containers.Network
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.utility.DockerImageName

import java.net.http.HttpResponse

/**
 * Shared AppSec tests for the WordPress fixtures. Concrete subclasses supply
 * the {@code @Container} static fields ({@code CONTAINER} + {@code MYSQL}),
 * declare {@code isExpectedVersion}, and invoke {@link #installWordPress} in
 * a static {@code @BeforeAll}. The WordPress core APIs exercised here
 * ({@code wp_authenticate}, {@code register_new_user},
 * {@code wp_validate_auth_cookie}) have been stable since WP 3.x, so the test
 * body does not vary across supported versions.
 */
@Slf4j
abstract class AbstractWordPressAppsecTests {

    AppSecContainer getContainer() {
        getClass().CONTAINER
    }

    /**
     * MySQL container for a WordPress suite, reachable from the AppSec
     * container as {@code mysql-integration} on {@code network}.
     *
     * @param mysqlVersion image tag of {@code library/mysql}, e.g. {@code 8.0}
     * @param databaseName database created for this suite (and used by its
     *        fixture's {@code wp-config.php})
     */
    protected static MySQLContainer newMysqlContainer(Network network, String mysqlVersion, String databaseName) {
        new MySQLContainer(
                DockerImageName.parse("${System.getProperty('DOCKER_MIRROR') ?: 'docker.io'}/library/mysql:${mysqlVersion}")
                        .asCompatibleSubstituteFor('mysql'))
                .withDatabaseName(databaseName)
                .withUsername('test')
                .withPassword('test')
                .withNetwork(network)
                .withNetworkAliases('mysql-integration')
                // Wait for the SECOND "ready for connections": the mysql image boots an
                // init-only --skip-networking server first, emits the message, shuts down,
                // then starts the real networked server. Waiting once can race wp-cli into
                // the gap between the two.
                .waitingFor(Wait.forLogMessage(".*ready for connections.*", 2)) as MySQLContainer
    }

    /**
     * Install WordPress via wp-cli and seed the admin user. Called from each
     * subclass's static {@code @BeforeAll}, so the container is passed in:
     * {@code @BeforeAll} is static and can't see subclass fields through
     * {@code getClass()}. The fixture's {@code docker-init.sh} has already
     * pointed {@code wp-config.php} at the MySQL container, which subclasses
     * declare as a dependency of {@code CONTAINER}.
     */
    protected static void installWordPress(AppSecContainer container) {
        // Run wp-cli with tracing and AppSec disabled so these CLI processes
        // don't interfere with the FPM workers' sidecar/AppSec helper. FPM
        // keeps running throughout so the already-started AppSec helper
        // (launched by the container's initial FPM workers) remains active.
        def res = container.execInContainer('bash', '-c',
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

        def port = container.firstMappedPort
        res = container.execInContainer('bash', '-c',
                """export DD_TRACE_CLI_ENABLED=false DD_APPSEC_ENABLED=0
                   wp option update siteurl 'http://localhost:${port}' --path=/var/www/public --allow-root
                   wp option update home 'http://localhost:${port}' --path=/var/www/public --allow-root""")
        assert res.exitCode == 0 : "Failed to update WordPress URLs: ${res.stderr}"

        container.clearTraces()
    }

    @Test
    @Order(1)
    void 'Login success automated event'() {
        // admin/admin is the user created by `wp core install` in @BeforeAll.
        Trace trace = container.traceFromRequest('/login_trigger.php?user=admin&pass=admin') {
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
        Trace trace = container.traceFromRequest('/login_trigger.php?user=admin&pass=wrong') {
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
        Trace trace = container.traceFromRequest('/login_trigger.php?user=ghost&pass=whatever') {
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
        Trace trace = container.traceFromRequest(
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
        Trace trace = container.traceFromRequest('/behind_auth_trigger.php') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        Span span = trace.first()
        assert span.meta."usr.id" == "1"
        assert span.meta."_dd.appsec.usr.id" == "1"
        assert span.meta."_dd.appsec.user.collection_mode" == "identification"
    }
}
