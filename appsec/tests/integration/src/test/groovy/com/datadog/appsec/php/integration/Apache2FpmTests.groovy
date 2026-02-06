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
class Apache2FpmTests implements CommonTests, SamplingTestsInFpm, EndpointFallbackSamplingTests {
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
    void 'trace rate limit resets after one second'() {
        setRateLimit('1')
        try {
            def req = container.buildReq('/hello.php')
                    .header('User-Agent', 'TraceTagging/v2').GET().build()
            def trace1 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            assert trace1.first().metrics._sampling_priority_v1 == 2.0d

            Thread.sleep(1200)

            def trace2 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            assert trace2.first().metrics._sampling_priority_v1 == 2.0d
        } finally {
            resetFpm()
        }
    }

    @Test
    void 'trace rate limit unlimited when set to zero'() {
        setRateLimit('0')
        try {
            def req = container.buildReq('/hello.php')
                    .header('User-Agent', 'TraceTagging/v2').GET().build()
            def trace1 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            assert trace1.first().metrics._sampling_priority_v1 == 2.0d

            def trace2 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            assert trace2.first().metrics._sampling_priority_v1 == 2.0d
        } finally {
            resetFpm()
        }
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
    void 'trace rate limit does not force keep when keep is false'() {
        // Limit to 1 keep per second; send two back-to-back requests that would normally set user_keep
        // using UA TraceTagging/v2 (covered by tagging rules).
        setRateLimit('1')
        try {
            def req = container.buildReq('/hello.php')
                    .header('User-Agent', 'TraceTagging/v2').GET().build()
            def trace1 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            def span1 = trace1.first()
            assert span1.metrics._sampling_priority_v1 == 2.0d

            def trace2 = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }
            def span2 = trace2.first()
            assert span2.metrics._sampling_priority_v1 < 2.0d
        } finally {
            resetFpm()
        }
    }

    @Test
    void 'resource renaming auto-enabled with appsec'() {
        // By default, DD_APPSEC_ENABLED=true is set but DD_TRACE_RESOURCE_RENAMING_ENABLED is not set.
        def req = container.buildReq('/hello.php').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }

        def span = trace.first()
        assert span.metrics."_dd.appsec.enabled" == 1.0d : "AppSec should be enabled"
        assert span.meta."http.endpoint" == '/hello.php' : "http.endpoint tag should be set when AppSec is enabled"
    }

    @Test
    void 'resource renaming disabled when explicitly set to false'() {
        // When DD_TRACE_RESOURCE_RENAMING_ENABLED=false is explicitly set, resource renaming should be disabled
        // even when AppSec is enabled
        disableEndpointRenaming()

        try {
            def req = container.buildReq('/hello.php').GET().build()
            def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
                assert resp.body() == 'Hello world!'
            }

            def span = trace.first()
            assert span.metrics."_dd.appsec.enabled" == 1.0d : "AppSec should still be enabled"
            assert span.meta."http.endpoint" == null : "http.endpoint tag should NOT be set when resource renaming is explicitly disabled"
        } finally {
            resetFpm()
        }
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

    @Test
    void 'span contains runtime id meta'() {
        def req = container.buildReq('/hello.php').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body() == 'Hello world!'
        }
        def span = trace.first()
        assert span.meta."_dd.runtime_id" instanceof String
        assert span.meta."_dd.runtime_id".size() > 0
    }

}
