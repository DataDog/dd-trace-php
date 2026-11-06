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
 * Covers Laravel 11.x — same event-based auth hooks as 8.x/9.x/10.x, but
 * exercised against the slimmed-down Laravel 11 skeleton (no `app/Http/Kernel.php`,
 * middleware configured via `bootstrap/app.php`). Pinned to PHP 8.3-release.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Laravel11xTests extends AbstractLaravelAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.3') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Laravel/Version_11_x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // register() ends with `redirect('/simple')` → 302.
    @Override
    int getExpectedSignupStatus() { 302 }
}
