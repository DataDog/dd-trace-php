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
class Symfony62Tests extends AbstractSymfonyAppsecTests {
    static boolean expectedVersion = phpVersion.contains('8.1') && !variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: '../../../tests/Frameworks/Symfony/Version_6_2',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    // 13 routes come from the fixture controllers; `_errors` is dev-only (gated
    // by `when@dev` in config/routes/framework.yaml) and docker-init.sh runs
    // under APP_ENV=prod, so `/_error/{code}.{_format}` is not registered.
    @Override
    int expectedEndpointCount() { 13 }

    @Override
    List<List<String>> expectedEndpoints() {
        [
                ['/', 'GET', 'GET /'],
                ['/dynamic-path/{param01}', 'GET', 'GET /dynamic-path/{param01}'],
                ['/caminho-dinamico/{param01}', 'GET', 'GET /caminho-dinamico/{param01}'],
                ['/login', 'GET', 'GET /login'],
                ['/register', 'GET', 'GET /register'],
                ['/simple', 'GET', 'GET /simple'],
                ['/simple_view', 'GET', 'GET /simple_view'],
                ['/dynamic_route/{param01}/{param02}', 'GET', 'GET /dynamic_route/{param01}/{param02}'],
                ['/error', 'GET', 'GET /error'],
                ['/behind_auth', 'GET', 'GET /behind_auth'],
                ['/telemetry', 'GET', 'GET /telemetry'],
                ['/lucky/number', 'GET', 'GET /lucky/number'],
                ['/lucky/fail', 'GET', 'GET /lucky/fail'],
        ]
    }
}
