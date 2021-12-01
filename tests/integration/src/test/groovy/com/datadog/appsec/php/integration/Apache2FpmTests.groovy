package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getTracerVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isZts')
class Apache2FpmTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    imageDir: 'apache2-fpm',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    tracerVersion: tracerVersion
            )
}
