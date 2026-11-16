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

@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Laravel8xTests extends AbstractLaravelAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.1') && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Laravel/Version_8_x',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // The 8.x fixture ships ~27 routes (feature-controller suite); the listed
    // entries below are the subset exercised by AppSec. Extras are accepted
    // via the base-class default (`isAllowUnenumeratedRoutes = true`).
    @Override
    List<List<String>> getExpectedEndpoints() {
        [
                ['/', 'GET', 'GET /'],
                ['login/auth', 'GET', 'GET login/auth'],
                ['login/signup', 'GET', 'GET login/signup'],
                ['dynamic-path/{param01}', 'GET', 'GET dynamic-path/{param01}'],
                ['api/user', 'GET', 'GET api/user'],
        ]
    }
}
