package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult

/**
 * Regression test for a ZTS-only crash in PHP_GSHUTDOWN_FUNCTION(ddtrace).
 *
 * Under Apache MPM event with MaxConnectionsPerChild 1, worker threads are
 * cancelled without calling tsrm_thread_exit(). PHP's ts_free_id then iterates
 * every thread's TSRM storage from the main thread and invokes
 * zm_globals_dtor_ddtrace for each per-thread slot.
 *
 * Only runs on ZTS variants (MPM event is only used on ZTS) and only when
 * -PcheckCoreDumps is passed.
 */
@Testcontainers
@Slf4j
@EnabledIf('isZtsAndCheckCoreDumps')
class ZtsGshutdownTests {
    /** Only enabled on ZTS variants and when -PcheckCoreDumps is passed. */
    static boolean isZtsAndCheckCoreDumps() {
        variant.contains('zts') && System.getProperty('checkCoreDumps') != null
    }

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            )

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    void 'no crash during GSHUTDOWN when MaxConnectionsPerChild 1 triggers ZTS worker lifecycle'() {
        long errorLogOffset = (CONTAINER.execInContainer('sh', '-c',
                'stat -c %s /tmp/logs/apache2/error.log 2>/dev/null || echo 0')
                .stdout.trim() as long)

        ExecResult backupResult = CONTAINER.execInContainer('sh', '-c',
                'cp /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.conf.bak_zts')
        assert backupResult.exitCode == 0

        try {
            // Append MaxConnectionsPerChild 1 + KeepAlive Off so each TCP connection
            // causes Apache to call clean_child_exit(), which destroys the APR child
            // pool and triggers PHP MSHUTDOWN + GSHUTDOWN.
            ExecResult cfgResult = CONTAINER.execInContainer('sh', '-c',
                    'OLD=$(pgrep -P $(pgrep -f /usr/sbin/apache2 | head -1))' +
                    ' && echo "MaxConnectionsPerChild 1\nKeepAlive Off" >> /etc/apache2/mods-enabled/mpm_event.conf' +
                    ' && apache2ctl restart' +
                    ' && for p in $OLD; do while kill -0 $p 2>/dev/null; do sleep 0.05; done; done')
            assert cfgResult.exitCode == 0: "apache2 config failed: ${cfgResult.stderr}"

            String apacheParent = CONTAINER.execInContainer('sh', '-c',
                    'pgrep -f /usr/sbin/apache2 | head -1').stdout.trim()

            for (int i = 0; i < 3; i++) {
                // Snapshot workers before the request — MaxConnectionsPerChild 1
                // means exactly one worker will call clean_child_exit() after
                // responding, running PHP MSHUTDOWN/GSHUTDOWN before it exits.
                Set<String> workersBefore = CONTAINER.execInContainer('sh', '-c',
                        "pgrep -P $apacheParent").stdout.trim().readLines().toSet()

                CONTAINER.traceFromRequest('/hello.php', { HttpResponse<InputStream> resp ->
                    assert resp.statusCode() == 200: "request ${i} failed: ${resp.statusCode()}"
                })

                // Wait until at least one pre-request worker has exited, confirming
                // its GSHUTDOWN completed before we inspect for crashes.
                long deadline = System.currentTimeMillis() + 10_000
                while (System.currentTimeMillis() < deadline) {
                    Set<String> workersNow = CONTAINER.execInContainer('sh', '-c',
                            "pgrep -P $apacheParent").stdout.trim().readLines().toSet()
                    if (!workersNow.containsAll(workersBefore)) break
                    Thread.sleep(100)
                }
            }

            // Core dump detection is handled automatically by AppSecContainer.close().
            // Additionally check Apache's error.log for crashes that generate SIGABRT
            // before a core dump can be written (e.g. Rust allocator panics on
            // poisoned memory).
            ExecResult logCheck = CONTAINER.execInContainer('sh', '-c',
                    "tail -c +${errorLogOffset + 1} /tmp/logs/apache2/error.log")
            String errorLog = logCheck.stdout ?: ''
            assert !errorLog.contains('exit signal Aborted'):
                    "Apache worker exited via SIGABRT during GSHUTDOWN:\n" + errorLog
            assert !errorLog.contains('exit signal Segmentation'):
                    "Apache worker segfaulted during GSHUTDOWN:\n" + errorLog
        } finally {
            CONTAINER.execInContainer('sh', '-c',
                    'cp /etc/apache2/mods-enabled/mpm_event.conf.bak_zts' +
                    ' /etc/apache2/mods-enabled/mpm_event.conf' +
                    ' && apache2ctl restart')
        }
    }
}
