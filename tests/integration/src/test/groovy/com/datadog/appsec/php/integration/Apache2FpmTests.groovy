package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getTracerVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isZts')
class Apache2FpmTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    imageDir: 'apache2-fpm',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    tracerVersion: tracerVersion
            )


    @Test
    void 'php-fpm -i does not launch helper'() {
        ExecResult res = CONTAINER.execInContainer('mkdir', '/tmp/cli/')

        res = CONTAINER.execInContainer(
                'bash', '-c',
                'php-fpm -d extension=ddtrace.so -d extension=ddappsec.so ' +
                        '-d datadog.appsec.enabled=0 ' +
                        '-d datadog.appsec.helper_runtime_path=/tmp/cli ' +
                        '-i')
        if (res.exitCode != 0) {
            throw new AssertionError("Failed executing php-fpm -i: $res.stderr")
        }
        res = CONTAINER.execInContainer('/bin/bash', '-c',
            'test $(find /tmp/cli/ -maxdepth 1 -type s -name \'ddappsec_*_*.sock\' | wc -l) -eq 0')
        assert res.exitCode == 0

        res = CONTAINER.execInContainer(
                'bash', '-c',
                'php-fpm -d extension=ddtrace.so -d extension=ddappsec.so ' +
                        '-d datadog.appsec.enabled=1 ' +
                        '-d datadog.appsec.helper_runtime_path=/tmp/cli ' +
                        '-i')
        if (res.exitCode != 0) {
            throw new AssertionError("Failed executing php-fpm -i: $res.stderr")
        }
        res = CONTAINER.execInContainer('/bin/bash', '-c',
            'test $(find /tmp/cli/ -maxdepth 1 -type s -name \'ddappsec_*_*.sock\' | wc -l) -eq 0')
        assert res.exitCode == 0
    }

    @Test
    void 'Pool environment'() {
        def trace = container.traceFromRequest('/poolenv.php') { HttpURLConnection conn ->
            assert conn.responseCode == 200
            def content = conn.inputStream.text

            assert content.contains('Value of pool env is 10001')
        }
    }
}
