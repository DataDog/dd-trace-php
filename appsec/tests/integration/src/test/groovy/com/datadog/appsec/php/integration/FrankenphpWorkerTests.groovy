package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.containers.wait.strategy.Wait
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.time.Duration.ofSeconds

@Testcontainers
@Slf4j
@EnabledIf('isZts84')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class FrankenphpWorkerTests implements WorkerStrategyTests {

    static boolean zts84 = variant.contains('zts') && phpVersion.contains('8.4')
    boolean canBlockOnResponse = false
    String component = 'frankenphp'

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'frankenphp',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'frankenphp',
                    www_src: '_handlers',
            ).withEnv 'DD_REMOTE_CONFIG_ENABLED', 'false'

    @BeforeAll
    static void beforeAll() {
        Wait.forLogMessage('.*serving initial configuration.*', 1)
                .withStartupTimeout(ofSeconds(30))
                .waitUntilReady(CONTAINER)
    }
}
