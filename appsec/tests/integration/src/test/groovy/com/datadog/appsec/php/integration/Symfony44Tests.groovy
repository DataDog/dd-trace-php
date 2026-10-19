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
 * Covers the Symfony 4.x branch of {@code SymfonyIntegration} — the Guard-based
 * {@code AbstractFormLoginAuthenticator} hooks for login success/failure.
 * Symfony 4 does not populate the endpoints telemetry (gated out explicitly
 * in {@code SymfonyIntegration::loadSymfony}).
 *
 * Pinned to PHP 7.4-release to spread CI load off the busier PHP 8.x jobs.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Symfony44Tests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('7.4') && variant == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Version_4_4',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // Symfony 4 is explicitly excluded from endpoint collection in
    // SymfonyIntegration (`\strpos(self::$kernel::VERSION, '4.') !== 0`).
    @Override
    boolean supportsEndpointsCollection() { false }

    // The locale-aliased route (/caminho-dinamico) is only wired up in Symfony
    // 6+ fixtures where the #[Route(..., locale: ...)] attribute is available.
    @Override
    boolean supportsLocaleRoute() { false }

    // The bundled LoginFormAuthenticator returns `new Response('Logged in!')`
    // (200) when there is no stored target path, and `new Response(403)` on
    // failure instead of the stock 302-redirect behaviour.
    @Override
    int expectedLoginSuccessStatus() { 200 }

    @Override
    int expectedLoginFailureStatus() { 403 }
}
