package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * Covers the newest Laravel release (currently Laravel 12). Same event-based
 * auth hooks as 8.x+. Pinned to PHP 8.4-release; the fixture's docker-init.sh
 * runs {@code composer update} on container startup, so the resolved
 * dependency set is pinned by the container's PHP version rather than by a
 * committed lock file.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class LaravelLatestTests extends AbstractLaravelAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.4') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Laravel/Latest',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Override
    int getExpectedSignupStatus() { 302 }
}
