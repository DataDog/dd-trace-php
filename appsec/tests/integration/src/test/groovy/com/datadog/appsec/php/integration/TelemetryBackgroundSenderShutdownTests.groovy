package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.containers.Container.ExecResult
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.TelemetryHelpers.BGS_SERVICE
import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * What the background sender does while its process goes away: submit the counters it has
 * accumulated since the last flush from MSHUTDOWN (see ddtrace_mshutdown()), while the sidecar
 * can still address the application.
 *
 * A single-request CLI process isolates the process-exit path: its trace is queued before
 * telemetry finalize, and the sender is synchronously drained later in MSHUTDOWN. The FPM
 * workers, by contrast, are killed abruptly at the end of a run and never reach MSHUTDOWN.
 *
 * No request is ever served in this container. Since the background sender's application is
 * synthetic and shared by every process (see {@link TelemetryBackgroundSenderTests}), request
 * traffic would make any {@code trace_api} point observed here unattributable — that is what
 * keeps this apart from the request-path class, which has a container of its own.
 */
@Testcontainers
@DisabledIf('isDisabled')
class TelemetryBackgroundSenderShutdownTests {
    static boolean disabled = phpVersion != '8.2'

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

    private static final String FLUSH_PROBE_SERVICE = 'bgs_flush_probe'
    private static final long METRICS_WAIT_TIMEOUT_MS = 30_000
    private static final long METRICS_POLL_INTERVAL_MS = 500

    /**
     * The process it starts is the only one in the container that can have produced a trace_api
     * point: the metrics carry no process identity, so anything already queued for the synthetic
     * service would satisfy the assertion below.
     *
     * The sidecar buffers the points in the telemetry worker for this service/env and emits them
     * on its next flush (DD_TELEMETRY_HEARTBEAT_INTERVAL, 10 s here), hence the generous wait.
     */
    @Test
    void 'metrics accumulated during shutdown are submitted'() {
        ExecResult res = CONTAINER.execInContainer('sh', '-c',
                "DD_SERVICE=${FLUSH_PROBE_SERVICE} php -r 'usleep(300 * 1000);'; echo status=\$?".toString())
        assert res.stdout.readLines().last() == 'status=0' : "${res.stdout}\n${res.stderr}"

        // consume the trace this generated, or @FailOnUnmatchedTraces trips
        assert CONTAINER.nextCapturedTrace() != null

        List<Metric> series = []
        long deadline = System.currentTimeMillis() + METRICS_WAIT_TIMEOUT_MS
        while (!series.any { it.name == 'trace_api.requests' } &&
                System.currentTimeMillis() < deadline) {
            series.addAll(TelemetryHelpers.drainMetricSeries(CONTAINER, BGS_SERVICE, 0))
            if (!series.any { it.name == 'trace_api.requests' }) {
                long remaining = deadline - System.currentTimeMillis()
                if (remaining > 0) {
                    Thread.sleep(Math.min(METRICS_POLL_INTERVAL_MS, remaining))
                }
            }
        }

        Metric requests = series.find { it.name == 'trace_api.requests' }
        assert requests != null : "no trace_api.requests for ${BGS_SERVICE}; got ${series*.name}"
        assert requests.namespace == 'tracers'
        assert requests.points[0][1] >= 1.0

        Metric responses = series.find { it.name == 'trace_api.responses' }
        assert responses != null : 'trace_api.responses not reported at shutdown'
        assert 'status_code:2xx' in responses.tags
    }
}
