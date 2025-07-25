package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isZts')
class Apache2FpmTests implements CommonTests, SamplingTestsInFpm {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

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
        container.traceFromRequest('/poolenv.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text

            assert content.contains('Value of pool env is 10001')
        }
    }

    void setRateLimit(String limit) {
        def res = container.execInContainer(
                'bash', '-c',
                """kill -9 `pgrep php-fpm`;
               export DD_APPSEC_TRACE_RATE_LIMIT=$limit;
               php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini""")
        assert res.exitCode == 0
    }

    private void resetFpm() {
        container.execInContainer(
                'bash', '-c',
                '''kill -9 `pgrep php-fpm`;
               php-fpm -y /etc/php-fpm.conf -c /etc/php/php.ini''')
    }

    @Test
    void 'test sampling priority'() {
        // Set rate limit to 5 to ensure fewer than 15 events get sampling priority 2
        setRateLimit('5')

        try {
            def results = (1..15).collect {
                def req = container.buildReq('/hello.php')
                        .header('User-Agent', "Arachni/v1")
                        .GET().build()

                def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                    resp.body() == 'Hello world!'
                }

                trace.first().metrics._sampling_priority_v1
            }.toList()

            System.out.println("Sampling priorities: ${results}")

            def countWithPriority2 = results.count { it == 2.0d }

            assert countWithPriority2 < 15 : "Expected fewer than 15 events with sampling priority 2, but got ${countWithPriority2}"

            assert countWithPriority2 > 0 : "Expected at least some events with sampling priority 2, but got ${countWithPriority2}"
        } finally {
            // Reset php-fpm to default configuration
            resetFpm()
        }
    }

}
