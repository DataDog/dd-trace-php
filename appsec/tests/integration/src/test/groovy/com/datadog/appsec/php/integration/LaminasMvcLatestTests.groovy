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
 * Covers the current Laminas MVC skeleton (laminas-mvc 3.8). The newer
 * fixture ships a reduced route set (nine routes) and a single-mode
 * {@code LoginController} using the standard {@code /login/auth} +
 * {@code /behind_auth} literals. The exotic router types (Hostname, Method,
 * Placeholder, Regex, Scheme, Wildcard, Part/Chain) exercised by
 * {@code LaminasMvc33Tests} are not wired up here.
 *
 * Pinned to PHP 8.2 — composer.json allows 8.0–8.2 and 8.2 is the highest
 * supported slot that doesn't overlap with LaminasMvc33Tests (7.3–8.1).
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class LaminasMvcLatestTests extends AbstractLaminasAppsecTests {

    static boolean expectedVersion = getPhpVersion() == '8.2' && !getVariant().contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: getPhpVersion(),
                    phpVariant: getVariant(),
                    www: '../../../tests/Frameworks/Laminas/Mvc/Latest',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Override
    boolean isAllowUnenumeratedRoutes() { false }

    @Override
    List<List<String>> getExpectedEndpoints() {
        [
                ['/', '*', '* /'],
                ['/application[/:action]', '*', '* /application[/:action]'],
                ['/simple[/:key][/:pwd]', '*', '* /simple[/:key][/:pwd]'],
                ['/simple_view[/:key][/:pwd]', '*', '* /simple_view[/:key][/:pwd]'],
                ['/error[/:key][/:pwd]', '*', '* /error[/:key][/:pwd]'],
                ['/login/auth', '*', '* /login/auth'],
                ['/login/signup', '*', '* /login/signup'],
                ['/behind_auth', '*', '* /behind_auth'],
                ['/dynamic_route[/:param01[/static[/:param02]]]', '*',
                 '* /dynamic_route[/:param01[/static[/:param02]]]'],
        ]
    }
}
