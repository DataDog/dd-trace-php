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
 * Covers the Symfony 5.x branch of {@code SymfonyIntegration} — the
 * {@code AbstractAuthenticationListener::onSuccess / onFailure} hooks (the
 * post-Guard, pre-6.x authentication machinery). The fixture uses the
 * built-in {@code form_login} authenticator, so default 302 responses apply.
 *
 * Pinned to PHP 7.4-release: the Symfony 5.2 lockfile targets PHP 7.4 and
 * running the suite there keeps load off the PHP 8.x jobs.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Symfony52Tests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('7.4') && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Version_5_2',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // The locale option on #[Route] is Symfony 5.3+. The 5.2 fixture only
    // defines the canonical /dynamic-path/{param01} route.
    @Override
    boolean supportsLocaleRoute() { false }
}
