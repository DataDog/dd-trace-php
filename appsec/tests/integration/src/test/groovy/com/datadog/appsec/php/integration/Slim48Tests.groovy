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
 * Covers Slim 4.8. TODO(confirm): PHP version pin.
 */
@Testcontainers
@EnabledIf('isExpectedVersion')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Slim48Tests extends AbstractSlimAppsecTests {

    static boolean expectedVersion = getPhpVersion() == '8.0' && getVariant() == 'release'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: getPhpVersion(),
                    phpVariant: getVariant(),
                    www: '../../../tests/Frameworks/Slim/Version_4_8',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Override
    String getExpectedRootHttpRoute() { '/' }

    @Override
    boolean isAllowUnenumeratedRoutes() { false }

    // Mirrors tests/Frameworks/Slim/Version_4_8/app/routes.php.
    @Override
    List<List<String>> getExpectedEndpoints() {
        [
                ['/{routes:.*}', 'OPTIONS', 'OPTIONS /{routes:.*}'],
                ['/', 'GET', 'GET /'],
                ['/users', 'GET', 'GET /users'],
                ['/users/{id}', 'GET', 'GET /users/{id}'],
                ['/simple', 'GET', 'GET /simple'],
                ['/simple_view', 'GET', 'GET /simple_view'],
                ['/error', 'GET', 'GET /error'],
                ['/parameterized/{value}', 'GET', 'GET /parameterized/{value}'],
                ['/dynamic-path/{param01}', 'GET', 'GET /dynamic-path/{param01}'],
                ['/dynamic_route/{param01}[/static/{param02}]', 'GET',
                 'GET /dynamic_route/{param01}[/static/{param02}]'],
                ['/multi-method', 'GET', 'GET /multi-method'],
                ['/multi-method', 'POST', 'POST /multi-method'],
                ['/telemetry', 'GET', 'GET /telemetry'],
        ]
    }
}
