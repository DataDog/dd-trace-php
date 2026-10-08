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
 * Covers Laravel 9.x — same event-based auth hooks as 8.x (same
 * {@code Illuminate\Auth\Events\*} + {@code SessionGuard} wrappers), exercised
 * against the Laravel 9 bundle.
 *
 * Pinned to PHP 8.0-release to spread CI load off the busier PHP 8.1+ jobs.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Laravel9xTests extends AbstractLaravelAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.0') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Laravel/Version_9_x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // The 9.x scaffolded LoginTestController ends with `redirect('/simple')`
    // (vs. 8.x which returns `response('User created', 200)`).
    @Override
    int getExpectedSignupStatus() { 302 }
}
