package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.phpVersion
import static com.datadog.appsec.php.integration.TestParams.tracerVersion
import static com.datadog.appsec.php.integration.TestParams.variant

@Testcontainers
@Slf4j
@DisabledIf('isZts')
class AlpineApache2FpmTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    imageDir: 'alpine-apache2-fpm',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    tracerVersion: tracerVersion
            )
}
