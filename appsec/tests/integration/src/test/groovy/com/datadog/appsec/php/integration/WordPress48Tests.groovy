package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.MySQLContainer
import org.testcontainers.containers.Network
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers
import org.testcontainers.utility.DockerImageName

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * Covers WordPress 4.8 (June 2017) running on the oldest PHP version the
 * appsec extension supports. WP 4.8 pre-dates REST-first integrations but
 * still ships {@code wp_authenticate}, {@code register_new_user} and
 * {@code wp_validate_auth_cookie} — the three functions
 * {@code WordPressIntegration} hooks for auth events.
 */
@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isExpectedVersion')
class WordPress48Tests extends AbstractWordPressAppsecTests {
    static boolean expectedVersion = phpVersion == '7.0' && !variant.contains('zts')

    private static Network network = Network.newNetwork()

    @Container
    // mysql:5.7 (not 8.0): PHP 7.0's mysqli does not support MySQL 8's default
    // caching_sha2_password auth plugin (added in PHP 7.4). 5.7 defaults to
    // mysql_native_password, which every mysqli version understands.
    private static MySQLContainer MYSQL = new MySQLContainer(
            DockerImageName.parse("${System.getProperty('DOCKER_MIRROR') ?: 'docker.io'}/library/mysql:5.7")
                    .asCompatibleSubstituteFor('mysql'))
            .withDatabaseName('wp48')
            .withUsername('test')
            .withPassword('test')
            .withNetwork(network)
            .withNetworkAliases('mysql-integration')
            // Wait for the SECOND "ready for connections": the mysql image boots an
            // init-only --skip-networking server first, emits the message, shuts down,
            // then starts the real networked server. Waiting once can race wp-cli into
            // the gap between the two.
            .waitingFor(Wait.forLogMessage(".*ready for connections.*", 2)) as MySQLContainer

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/WordPress/Version_4_8',
            ).withNetwork(network) as AppSecContainer

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER, MYSQL)
    }

    @BeforeAll
    static void setup() {
        installWordPress(CONTAINER, MYSQL)
    }
}
