package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * Standalone test class for the extended-heartbeat re-emission behaviour.
 *
 * The default extended-heartbeat interval is 24h. To exercise it within the test
 * timeout, this class starts its container with DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL=15
 * (kept separate from TelemetryTests so the persistent telemetry worker for that
 * class isn't already running with the long interval before this test runs).
 *
 * Per the spec (instrumentation-telemetry-api-docs/Source/ApiDocs/v2/openapi.yaml
 * `AppExtendedHeartbeat`), the payload must carry configuration + dependencies +
 * integrations so the agent can reconstruct application records on data loss.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
class TelemetryExtendedHeartbeatTests {
    static boolean disabled = variant.contains('zts') || phpVersion != '8.4'

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            ) {
                @Override
                void configure() {
                    super.configure()
                    // Short interval so the extended heartbeat fires within the test.
                    withEnv 'DD_TELEMETRY_EXTENDED_HEARTBEAT_INTERVAL', '15'
                }
            }

    @Test
    void 'extended heartbeat re-emits configuration, dependencies and integrations'() {
        // Trigger the integration loaders for phpredis (Redis class instantiation) and
        // exec (system() call). These cause the tracer to push AddIntegration to the
        // sidecar.
        CONTAINER.traceFromRequest('/custom_integrations.php?integrations[]=redis&integrations[]=exec') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }

        // Stage 1: verify the regular flush path actually emits phpredis (and exec)
        // in an app-started or app-integrations-change message. This proves we're
        // about to test the heartbeat re-emitting *previously-flushed* data.
        Set<String> flushed = [] as Set
        TelemetryHelpers.waitForIntegrations(CONTAINER, 30) { msgs ->
            msgs.each { (it.integrations ?: []).each { flushed << it.name } }
            'phpredis' in flushed && 'exec' in flushed
        }
        assert 'phpredis' in flushed : "phpredis not emitted via app-started/app-integrations-change; saw: ${flushed}"
        assert 'exec' in flushed : "exec not emitted via app-started/app-integrations-change; saw: ${flushed}"

        // Stage 2: the extended heartbeat must re-emit the full triple
        // (configuration + dependencies + integrations).
        CONTAINER.drainTelemetry(0)
        def hbs = TelemetryHelpers.waitForExtendedHeartbeat(CONTAINER, 35) { !it.empty }
        assert !hbs.empty : 'No app-extended-heartbeat received within 35s'
        def hb = hbs.first()
        assert hb.configuration != null : 'heartbeat must include configuration field'
        assert hb.dependencies  != null : 'heartbeat must include dependencies field'
        assert hb.integrations  != null : 'heartbeat must include integrations field'
        assert 'phpredis' in hb.integrations*.name :
                "heartbeat should re-emit phpredis; got: ${hb.integrations*.name}"

        // Stage 3: regression check for the leak that the libdd_telemetry fix addresses.
        // After the heartbeat, the worker should pop the re-queued items out of unflushed.
        // So when a NEW integration is loaded next, the following regular app-integrations-change
        // must contain only the new integration, NOT the previously-flushed-and-re-queued phpredis.
        CONTAINER.drainTelemetry(0)
        CONTAINER.traceFromRequest('/custom_integrations.php?integrations[]=fs') {
            HttpResponse<InputStream> resp -> assert resp.statusCode() == 200
        }
        Set<String> followup = [] as Set
        TelemetryHelpers.waitForIntegrations(CONTAINER, 30) { msgs ->
            msgs.each { (it.integrations ?: []).each { followup << it.name } }
            'filesystem' in followup
        }
        assert 'filesystem' in followup : 'No app-integrations-change with filesystem within 30s after heartbeat'
        assert !('phpredis' in followup) :
                "phpredis must not be re-emitted after the heartbeat; got: ${followup}"
    }
}
