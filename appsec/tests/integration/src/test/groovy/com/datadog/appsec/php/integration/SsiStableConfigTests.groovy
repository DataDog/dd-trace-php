package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Tag
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@Tag("musl")
@EnabledIf('isSsi')
class SsiStableConfigTests {
    static boolean isSsi() {
        System.getProperty('SSI') == 'true'
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: variant.contains('musl') ? 'nginx-fpm-php' : 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'appsec can be enabled via stable config'() {
        ExecResult res = CONTAINER.execInContainer('bash', '-c',
                '''cat > /tmp/stable_config.yaml << 'YAML'
apm_configuration_default:
  DD_PROFILING_ENABLED: 1
  DD_APPSEC_ENABLED: 1
YAML
''')
        assert res.exitCode == 0 : "Failed to write stable config YAML: $res.stderr"

        // Run PHP CLI with the loader and stable config env vars
        res = CONTAINER.execInContainer('bash', '-c',
                '''DD_LOADER_PACKAGE_PATH=/tmp/dd-package \
_DD_TEST_LIBRARY_CONFIG_FLEET_FILE=/tmp/stable_config.yaml \
_DD_TEST_LIBRARY_CONFIG_LOCAL_FILE=/tmp/stable_config.yaml \
php -n -dzend_extension=/loader-ssi/dd_library_loader.so \
    -r "echo ini_get('datadog.appsec.enabled');"''')

        log.info "PHP CLI stdout: $res.stdout"
        log.info "PHP CLI stderr: $res.stderr"

        assert res.exitCode == 0 : "PHP CLI exited with code $res.exitCode: $res.stderr"
        assert res.stdout.trim().contains('1') :
                "Expected datadog.appsec.enabled to be '1', got: '$res.stdout'"

        Trace trace = CONTAINER.nextCapturedTrace()
        assert trace.first().type == 'cli'
    }
}
