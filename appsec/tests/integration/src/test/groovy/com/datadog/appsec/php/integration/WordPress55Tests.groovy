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
 * Covers WordPress 5.5 (August 2020) on PHP 7.4. 5.5 is the first branch
 * with block-editor-first defaults; the auth entry points
 * ({@code wp_authenticate}, {@code register_new_user},
 * {@code wp_validate_auth_cookie}) are unchanged from older WPs.
 */
@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isExpectedVersion')
class WordPress55Tests extends AbstractWordPressAppsecTests {
    static boolean expectedVersion = phpVersion == '7.4' && !variant.contains('zts')

    private static Network network = Network.newNetwork()

    @Container
    private static MySQLContainer MYSQL = new MySQLContainer(
            DockerImageName.parse("${System.getProperty('DOCKER_MIRROR') ?: 'docker.io'}/library/mysql:8.0")
                    .asCompatibleSubstituteFor('mysql'))
            .withDatabaseName('wp55')
            .withUsername('test')
            .withPassword('test')
            .withNetwork(network)
            .withNetworkAliases('mysql-integration')
            .waitingFor(Wait.forLogMessage(".*ready for connections.*", 1)) as MySQLContainer

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/WordPress/Version_5_5',
            ).withNetwork(network) as AppSecContainer

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER, MYSQL)
    }

    @BeforeAll
    static void setup() {
        installWordPress(CONTAINER, MYSQL)
    }
}
