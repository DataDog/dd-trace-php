package com.datadog.appsec.php.integration

import com.datadog.appsec.php.TelemetryHelpers
import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.LogFile
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Assumptions
import org.junit.jupiter.api.BeforeAll
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static com.datadog.appsec.php.integration.TestParams.phpVersionAtLeast

@Testcontainers
@Slf4j
@EnabledIf('isExpectedVersion')
class RoadRunnerTests implements WorkerStrategyTests {
    static boolean expectedVersion = phpVersionAtLeast('7.4') && !variant.contains('zts')
    boolean canBlockOnResponse = true
    String component = 'roadrunner'

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'roadrunner',
                    www_src: '_handlers',
            ).withEnv 'DD_REMOTE_CONFIG_ENABLED', 'false'

    @BeforeAll
    static void beforeAll() {
        // wait until roadrunner is running
        long deadline = System.currentTimeMillis() + 300_000
        while (CONTAINER.execInContainer('grep', 'http server was started', '/tmp/logs/rr.log').exitCode != 0) {
            if (System.currentTimeMillis() > deadline) {
                throw new RuntimeException('Roadrunner did not start on time (see output of run.sh)')
            }
            Thread.sleep(500)
        }
    }

    /**
     * Regression test for the AppSec helper "unexpected command RequestExec" bug:
     * RequestExec sent not between a request init and a request shutdown.
     *
     * Also covers the other half of that behaviour, RFC-1012's
     * appsec.rasp.rule.skipped: a RASP evaluation that reached beyond the request must
     * not merely be dropped, it must be accounted for. Asserted here rather than in a
     * test of its own so that the metric is attributable to this request — any other
     * test hitting this route would emit the same series.
     */
    @Test
    void 'no unexpected RequestExec in outer loop after post-respond fopen'() {
        Assumptions.assumeTrue(TestParams.usesHelperRust(),
                'This bug only manifests on the Rust helper (strict outer/inner loop state machine).')

        LogFile helperLog = new LogFile(CONTAINER, 'helper.log')
        helperLog.markEndPos()

        // PostRespondRaspHandler sets a callback that runs RASP instrumentation
        // after respond() returns. By that point, request_shutdown has been sent
        // via the response_committed hook. If push_addresses() still reaches the
        // helper (socket open, active=true), it sends RequestExec into the outer loop.
        CONTAINER.traceFromRequest('/post-respond-rasp') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // Follow-up request verifies the connection is still usable.
        CONTAINER.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        List<String> lines = helperLog.linesSinceMark
        log.info("Helper log since offset:\n{}", lines.join('\n'))

        assert !lines.any { it.contains('unexpected command RequestExec') } :
                "Helper received RequestExec in outer loop. " +
                "Relevant log:\n" +
                lines.findAll {
                    it.contains('unexpected command') || it.contains('error in request loop')
                }.join('\n')

        // Draining telemetry means waiting on the metric interval (hardcoded to 10s), so
        // pay it on one version only instead of on every version this class covers. The
        // assertions above stay unconditional.
        if (phpVersion != '8.5') {
            return
        }

        TelemetryHelpers.Metric lfiSkipped
        TelemetryHelpers.Metric ssrfSkipped

        TelemetryHelpers.waitForMetrics(CONTAINER, 30) { List<TelemetryHelpers.GenerateMetrics> messages ->
            def allSeries = messages.collectMany { it.series }
            lfiSkipped = lfiSkipped ?: allSeries.find {
                it.name == 'rasp.rule.skipped' && 'rule_type:lfi' in it.tags
            }
            ssrfSkipped = ssrfSkipped ?: allSeries.find {
                it.name == 'rasp.rule.skipped' && 'rule_type:ssrf' in it.tags
            }
            lfiSkipped && ssrfSkipped
        }

        // The handler makes exactly one push of each kind, and this is the only test
        // requesting the route, so the counts are exact.
        assert lfiSkipped != null : 'rasp.rule.skipped for lfi not found'
        assert lfiSkipped.namespace == 'appsec'
        assert lfiSkipped.type == 'count'
        assert lfiSkipped.points[0][1] == 1.0
        assert 'reason:out-of-request' in lfiSkipped.tags
        // LFI has no variant — tag must be absent (sidecar rejects empty tag values)
        assert !lfiSkipped.tags.any { it.startsWith('rule_variant:') }
        // emitted by the extension, never by the helper
        assert !lfiSkipped.tags.any { it.startsWith('waf_version:') }

        assert ssrfSkipped != null : 'rasp.rule.skipped for ssrf not found'
        assert ssrfSkipped.namespace == 'appsec'
        assert ssrfSkipped.type == 'count'
        assert ssrfSkipped.points[0][1] == 1.0
        assert 'reason:out-of-request' in ssrfSkipped.tags
        assert 'rule_variant:request' in ssrfSkipped.tags
        assert !ssrfSkipped.tags.any { it.startsWith('waf_version:') }
    }

    /**
     * Regression test for the AppSec helper "unexpected command RequestExec" bug,
     * variant where the post-respond RequestExec sender is the user-tracking SDK
     * (track_user_login_success -> dd_find_and_apply_verdict_for_user) rather than
     * push_addresses.
     */
    @Test
    void 'no unexpected RequestExec in outer loop after post-respond track_user_login'() {
        Assumptions.assumeTrue(TestParams.usesHelperRust(),
                'This bug only manifests on the Rust helper (strict outer/inner loop state machine).')

        LogFile helperLog = new LogFile(CONTAINER, 'helper.log')
        helperLog.markEndPos()

        // PostRespondTrackUserHandler sets a callback that calls
        // track_user_login_success() after respond() returns. By that point,
        // request_shutdown has been sent via the response_committed hook. If
        // dd_find_and_apply_verdict_for_user still reaches the helper (socket
        // open, active=true), it sends RequestExec into the outer loop.
        CONTAINER.traceFromRequest('/post-respond-track-user') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        // Follow-up request verifies the connection is still usable.
        CONTAINER.traceFromRequest('/') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
        }

        List<String> lines = helperLog.linesSinceMark
        log.info("Helper log since offset:\n{}", lines.join('\n'))

        assert !lines.any { it.contains('unexpected command RequestExec') } :
                "Helper received RequestExec in outer loop. " +
                "Relevant log:\n" +
                lines.findAll {
                    it.contains('unexpected command') || it.contains('error in request loop')
                }.join('\n')
    }
}
