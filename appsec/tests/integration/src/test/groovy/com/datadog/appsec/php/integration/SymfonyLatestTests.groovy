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
 * Covers the Symfony 8.x branch — same {@code FormLoginAuthenticator} wrappers
 * as 6.x/7.x but exercised against the newest bundle layout
 * (e.g. {@code Routing\Attribute\Route} rather than {@code Routing\Annotation\Route}).
 *
 * Pinned to PHP 8.4-release, matching the fixture's committed composer.lock-php84.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class SymfonyLatestTests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.4') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Latest',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }
}
