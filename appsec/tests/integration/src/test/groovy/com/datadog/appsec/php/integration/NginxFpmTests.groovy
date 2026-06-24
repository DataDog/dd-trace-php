package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.LogFile
import com.datadog.appsec.php.docker.PhpFpm
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Assumptions
import org.junit.jupiter.api.Tag
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.DisabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@Slf4j
@DisabledIf('isZts')
@Tag("musl")
class NginxFpmTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'nginx-fpm-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'Pool environment'() {
        container.traceFromRequest('/poolenv.php') { HttpResponse<InputStream> resp ->
            assert resp.statusCode() == 200
            def content = resp.body().text

            assert content.contains('Value of pool env is 10001')
        }
    }

    /**
     * Regression test: OOM inside dd_entity_body_convert() (called from
     * dd_request_shutdown() via _request_pack()) triggers zend_bailout(), which
     * the per-module zend_try swallows, so the helper never gets RequestShutdown
     * and sees an out-of-order RequestInit on the next request.
     */
    @Test
    void 'no unexpected RequestInit due to RSHUTDOWN OOM bail'() {
        Assumptions.assumeTrue(System.getProperty('USE_HELPER_RUST') != null,
                'the C++ helper silently swallows out-of-order commands.')
        // PHP 8.3 release only (zts already excluded at class level): the debug
        // allocator's heap-protection turns the mid-allocation OOM bailout into
        // a spurious "zend_mm_heap corrupted" SIGABRT that masks the real bug.
        Assumptions.assumeTrue(phpVersion == '8.3' && !variant.contains('debug'),
                'requires a PHP 8.3 release build')

        // Drop the pool to a single worker so the OOM request and the follow-up
        // land on the same FPM process / helper socket.
        PhpFpm fpm = new PhpFpm(container)
        fpm.backupPoolConfig()

        try {
            fpm.setPoolValue('pm.max_children', '1')
            fpm.reload()

            LogFile helperLog = new LogFile(container, 'helper.log')
            helperLog.markEndPos()

            // Warm-up: establish the helper connection so it is in its outer loop
            // waiting for request_init before the OOM request.
            container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }

            // Trigger Pattern B: rshutdown_oom.php sets a 32M memory_limit, pins
            // ~28 MiB live in $GLOBALS, then emits ~400 KiB of JSON. During
            // RSHUTDOWN, dd_request_shutdown()'s _request_pack() callback parses
            // that body via dd_entity_body_convert(), overflowing the ceiling;
            // zend_bailout() fires before _omsg_send(), so the socket is untouched.
            container.traceFromRequest('/rshutdown_oom.php', ofString()) {
                HttpResponse<String> resp ->
                    // The script completes (OOM happens during RSHUTDOWN, after the
                    // response is on the wire).
                    assert resp.statusCode() == 200
            }

            // originally, the would actually fail here because the bailout during
            // rshutdown would skip _reset_globals() and _cur_req_span would not be
            // reset. This would either lead to a crash (502), or, if the same slot
            // was used for the span in the next request, for the span to be
            // prematurely deleted on the next request as _cur_req_span was being
            // replaced
            container.traceFromRequest('/hello.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200
            }

            List<String> lines = helperLog.linesSinceMark
            assert !lines.any { it.contains('unexpected command RequestInit') } :
                    'Error message found. Relevant helper log:\n' +
                    lines.findAll {
                        it.contains('unexpected command') || it.contains('error in request loop')
                    }.join('\n')
        } finally {
            fpm.restorePoolConfig()
            fpm.reload()
        }
    }

}
