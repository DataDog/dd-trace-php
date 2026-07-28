package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.TelemetryHelpers.Metric
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.TelemetryHelpers.BGS_SERVICE
import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant

/**
 * The in-process background sender (tracer/coms.c, enabled for every container through
 * DD_TRACE_SIDECAR_TRACE_SENDER=0) counts the HTTP exchanges it has with the agent and
 * reports them as the trace_api.requests / trace_api.responses telemetry metrics. Those
 * counters live on a connection-wide queue id of their own, so the sidecar needs an
 * application registered for that queue or it drops the payload with "No application
 * found".
 *
 * That application is deliberately synthetic ({@link TelemetryHelpers#BGS_SERVICE} / env
 * {@code none}): the counters describe the sender, not the traced application. It also means
 * every process in the container reports them under the same service, and the sidecar merges
 * same-service telemetry into a single worker, so the payloads carry nothing that ties them
 * back to the process that produced them.
 *
 * This class covers the ordinary path: counters produced by request traffic and flushed by a
 * later request. The paths that only run while a process is going away are in
 * {@link TelemetryBackgroundSenderShutdownTests}, which needs a container where no request has
 * ever been served — hence a separate class rather than an ordered method here.
 *
 * Nothing here is version-specific, so a single PHP version is enough, but both threading
 * modes are covered.
 */
@Testcontainers
@Slf4j
@DisabledIf('isDisabled')
class TelemetryBackgroundSenderTests {
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

    @Test
    void 'background sender trace_api metrics are reported'() {
        List<Metric> series = []
        for (int i = 0; i < 30 && !series.any { it.name == 'trace_api.requests' }; i++) {
            // The counters are only produced once the sender thread has actually talked to the
            // agent, and they are only flushed by a *later* request, so keep issuing them.
            CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }
            series.addAll(TelemetryHelpers.drainMetricSeries(CONTAINER, BGS_SERVICE))
        }

        Metric requests = series.find { it.name == 'trace_api.requests' }
        assert requests != null : "no trace_api.requests for ${BGS_SERVICE}; got ${series*.name}"
        assert requests.namespace == 'tracers'
        assert requests.type == 'count'
        assert requests.points[0][1] >= 1.0

        Metric responses = series.find { it.name == 'trace_api.responses' }
        assert responses != null : 'trace_api.responses metric not received'
        assert responses.namespace == 'tracers'
        assert responses.type == 'count'
        assert responses.points[0][1] >= 1.0
        assert 'status_code:2xx' in responses.tags
    }
}
