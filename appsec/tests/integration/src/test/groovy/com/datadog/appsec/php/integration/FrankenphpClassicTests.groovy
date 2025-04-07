package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

@Testcontainers
@Slf4j
@EnabledIf('isZts84')
class FrankenphpClassicTests  implements CommonTests {
    static boolean zts84 = variant.contains('zts') && phpVersion.contains('8.4')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'frankenphp',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )
                    .withCommand('classic')
                    .withEnv('DD_APPSEC_CLI_START_ON_RINIT', 'true')
                    .withEnv('OPCACHE', 'true')

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }
}
