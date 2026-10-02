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
 * Covers the Symfony 7.x branch of {@code SymfonyIntegration} — same
 * {@code FormLoginAuthenticator} wrappers as Symfony 6.x, exercised against
 * the Symfony 7.3 bundles (which have moved several internal classes).
 *
 * Pinned to PHP 8.3-release.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Symfony73Tests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.3') && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Version_7_3',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }
}
