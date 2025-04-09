package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast

@Testcontainers
@EnabledIf('isExpectedVersion')
class RoadRunnerTests implements WorkerStrategyTests {
    static boolean expectedVersion = phpVersionAtLeast('7.4') && !variant.contains('zts')
    boolean canBlockOnResponse = true
    String component = 'roadrunner'

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
                    www_src: '_handlers',
            ).withEnv 'DD_REMOTE_CONFIG_ENABLED', 'false'

    @BeforeAll
    static void beforeAll() {
        // wait until roadrunner is running
        long deadline = System.currentTimeMillis() + 300_000
        while (CONTAINER.execInContainer('grep', 'http server was started', '/tmp/logs/rr.log').exitCode != 0) {
            if (System.currentTimeMillis() > deadline) {
                throw new RuntimeException('Roadrunner did not start on time (see output of run.sh)')
            }
            Thread.sleep(500)
        }
    }
}
