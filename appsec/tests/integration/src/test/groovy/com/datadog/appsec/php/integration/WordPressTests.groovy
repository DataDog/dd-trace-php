package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
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
                   wp option update home 'http://localhost:${port}' --path=/var/www/public --allow-root""")
        assert res.exitCode == 0 : "Failed to update WordPress URLs: ${res.stderr}"

        CONTAINER.clearTraces()
    }

    @Test
    @Order(1)
    void 'Login failure with empty username triggers missing_user_login and missing_user_id telemetry'() {
        CONTAINER.traceFromRequest('/login_trigger.php?user=&pass=test') {
            HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
        }

        TelemetryHelpers.Metric missingUserLogin
        TelemetryHelpers.Metric missingUserId
        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            missingUserLogin = allSeries.find {
                it.name == 'appsec.instrum.user_auth.missing_user_login' &&
                        'event_type:login_failure' in it.tags &&
                        'framework:wordpress' in it.tags
            }
            missingUserId = allSeries.find {
                it.name == 'appsec.instrum.user_auth.missing_user_id' &&
                        'event_type:login_failure' in it.tags &&
                        'framework:wordpress' in it.tags
            }
            missingUserLogin != null && missingUserId != null
        }

        assert missingUserLogin != null
        assert missingUserLogin.namespace == 'appsec'
        assert missingUserLogin.points[0][1] >= 1.0
        assert missingUserLogin.type == 'count'

        assert missingUserId != null
        assert missingUserId.namespace == 'appsec'
        assert missingUserId.points[0][1] >= 1.0
        assert missingUserId.type == 'count'
    }
}
