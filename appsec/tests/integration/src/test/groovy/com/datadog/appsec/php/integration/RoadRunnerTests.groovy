package com.datadog.appsec.php.integration

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
     */
    @Test
    void 'no unexpected RequestExec in outer loop after post-respond fopen'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'This bug only manifests on the Rust helper (strict outer/inner loop state machine).')

        LogFile helperLog = new LogFile(CONTAINER, 'helper.log')
        helperLog.markEndPos()

        // PostRespondLfiHandler sets a callback that calls fopen('../etc/passwd')
        // after respond() returns. By that point, request_shutdown has been sent
        // via the response_committed hook. If push_addresses() still reaches the
        // helper (socket open, active=true), it sends RequestExec into the outer loop.
        CONTAINER.traceFromRequest('/post-respond-lfi') { HttpResponse<InputStream> resp ->
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

    /**
     * Regression test for the AppSec helper "unexpected command RequestExec" bug,
     * variant where the post-respond RequestExec sender is the user-tracking SDK
     * (track_user_login_success -> dd_find_and_apply_verdict_for_user) rather than
     * push_addresses.
     */
    @Test
    void 'no unexpected RequestExec in outer loop after post-respond track_user_login'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
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
