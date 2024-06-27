package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@DisabledIf('isZts')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class Apache2FpmTests implements CommonTests {
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

    /**
     * This test takes a long time (around 11-12 seconds) because the metric
     * interval is hardcoded to 10 seconds in the metrics.rs.
     *
     * So run it only in Apache2FpmTests to speed things up a bit, even though
     * this is not specific to Apache+FPM.
     */
    @Test
    @Order(1)
    void 'telemetry data is received'() {
        Trace trace = container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }
        assert trace.traceId != null

        // now do an attack
        HttpRequest req = container.buildReq('/hello.php')
                .header('User-Agent', 'Arachni/v1').GET().build()
        trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> resp ->
            assert resp.body().size() > 0
        }
        assert trace.traceId != null

        TelemetryHelpers.Metric wafInit
        TelemetryHelpers.Metric wafReq1
        TelemetryHelpers.Metric wafReq2

        List<TelemetryHelpers.GenerateMetrics> messages = []
        def deadline = System.currentTimeSeconds() + 30
        while ((!wafInit || !wafReq1 || !wafReq2) && System.currentTimeSeconds() < deadline) {
            def telData = container.drainTelemetry(500)
            messages.addAll(
                    TelemetryHelpers.filterMessages(telData, TelemetryHelpers.GenerateMetrics))
            def allSeries = messages.collectMany { it.series }
            wafInit = allSeries.find { it.name == 'waf.init' }
            wafReq1 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 2 }
            wafReq2 = allSeries.find { it.name == 'waf.requests' && it.tags.size() == 3 }
        }

        assert wafInit != null
        assert wafInit.namespace == 'appsec'
        assert wafInit.points[0][1] == 1.0
        assert 'success:true' in wafInit.tags
        assert wafInit.tags.size() == 3
        assert wafInit.type == 'count'
        assert wafInit.interval == 10

        assert wafReq1 != null
        assert wafReq1.namespace == 'appsec'
        assert wafReq1.points[0][1] == 1.0
        assert wafReq1.tags.find { it.startsWith('event_rules_version:') } != null
        assert wafReq1.tags.find { it.startsWith('waf_version:') } != null
        assert wafReq1.type == 'count'

        assert wafReq2 != null
        assert wafReq2.tags.find { it == 'rule_triggered:true' }
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
}
