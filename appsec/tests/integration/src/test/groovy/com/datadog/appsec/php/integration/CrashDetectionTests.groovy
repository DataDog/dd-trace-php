package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@TestMethodOrder(MethodOrderer.OrderAnnotation)
@EnabledIf('isEnabled')
class CrashDetectionTests {

    static boolean isEnabled() {
        checkCoreDumps && (phpVersion == '8.4' || phpVersion == '7.4')
    }

    private static boolean isCheckCoreDumps() {
        System.getProperty('checkCoreDumps') != null
    }

    @Container
    public static final AppSecContainer CONTAINER =
            new AppSecContainer(
                    workVolume: this.name,
                    baseTag: 'apache2-mod-php',
                    phpVersion: phpVersion,
                    phpVariant: variant,
                    www: 'base',
            ) {
                @Override
                void configure() {
                    super.configure()
                    withEnv('_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS', '5')
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    @Test
    @Order(1)
    void 'coredump is created and detected when sidecar crashes with SIGSEGV'() {
        // Warm up: ensure sidecar is running
        CONTAINER.traceFromRequest('/hello.php')
        CONTAINER.clearTraces()

        ExecResult killRes = CONTAINER.execInContainer('sh', '-c',
                'pid=$(pgrep -f "datadog-ipc-helper" | head -1) && ' +
                'kill -SEGV "$pid"')
        assert killRes.exitCode == 0: "Failed to send SIGSEGV to sidecar: ${killRes.stderr}"

        // Wait up to 5 s for the core file to appear
        String coreFile = null
        long deadline = System.currentTimeMillis() + 5_000
        while (System.currentTimeMillis() < deadline) {
            ExecResult findRes = CONTAINER.execInContainer('find', '/tmp/cores', '-type', 'f')
            if (findRes.exitCode == 0 && findRes.stdout.trim()) {
                coreFile = findRes.stdout.trim().readLines().first().trim()
                break
            }
            Thread.sleep(200)
        }

        assert coreFile: 'No core dump was created after SIGSEGV — coredump detection will not work'
        log.info('Core dump verified: {}', coreFile)

        // Clean up so container.close() does not also fail
        CONTAINER.clearCoreFiles()
    }

    /**
     * Verifies that the sidecar (helper-rust) exits cleanly and without a crash after all
     * PHP worker connections are dropped when Apache restarts.
     */
    @Test
    @Order(100)
    void 'sidecar exits normally with no crashes after Apache restart'() {
        CONTAINER.traceFromRequest('/hello.php')

        // Restart Apache so old workers exit and drop their sidecar connections.
        ExecResult restartRes = CONTAINER.execInContainer('sh', '-c',
                'OLD=$(pgrep -P $(pgrep -f /usr/sbin/apache2 | head -1))' +
                ' && service apache2 restart' +
                ' && for p in $OLD; do while kill -0 $p 2>/dev/null; do sleep 0.05; done; done')
        assert restartRes.exitCode == 0: "Failed to restart Apache: ${restartRes.stderr}"

        // Wait up to 15 s for the sidecar to exit (idle_linger_time=5 s + scheduling slack).
        boolean sidecarExited = false
        long deadline = System.currentTimeMillis() + 15_000
        while (System.currentTimeMillis() < deadline) {
            if (CONTAINER.execInContainer(
                    'pgrep', '-f', 'datadog-ipc-helper').exitCode != 0) {
                sidecarExited = true
                break
            }
            Thread.sleep(500)
        }

        String sidecarLog = CONTAINER.execInContainer(
                'sh', '-c', 'cat /tmp/logs/sidecar.log 2>/dev/null || true').stdout
        String helperLog = CONTAINER.execInContainer(
                'sh', '-c', 'cat /tmp/logs/helper.log 2>/dev/null || true').stdout
        log.info('sidecar.log tail after restart:\n{}',
                sidecarLog.readLines().takeRight(20).join('\n'))
        log.info('helper.log tail after restart:\n{}',
                helperLog.readLines().takeRight(20).join('\n'))

        if (!sidecarExited) {
            logSidecarThreadStacks()
        }

        assert sidecarExited: 'Sidecar did not exit within 15 s after Apache restart'

        assert sidecarLog.contains('No active connections'):
                "Expected 'No active connections' in sidecar.log — unexpected exit path."

        assert helperLog.contains('All client tasks completed gracefully'):
                "Expected 'All client tasks completed gracefully' in helper.log"
        assert helperLog.contains('Runtime shutdown complete'):
                "Expected 'Runtime shutdown complete' in helper.log"
        assert helperLog.contains('AppSec helper shutdown complete'):
                "Expected 'AppSec helper shutdown complete' in helper.log"
    }

    private static void logSidecarThreadStacks() {
        ExecResult gdbRes = CONTAINER.execInContainer('sh', '-c', '''
                pid=$(pgrep -f datadog-ipc-helper | head -1) || exit 0
                [ -n "$pid" ] || exit 0
                gdb -batch \
                  -ex "set pagination off" \
                  -ex "file /proc/$pid/exe" \
                  -ex "attach $pid" \
                  -ex "set language rust" \
                  -ex "thread apply all bt" \
                  -ex detach \
                  -ex quit 2>&1
                '''.stripIndent())
        if (gdbRes.stdout?.trim()) {
            log.error('Sidecar still running after timeout — gdb thread stacks:\n{}',
                    gdbRes.stdout.trim())
        }
        if (gdbRes.stderr?.trim()) {
            log.error('gdb stderr:\n{}', gdbRes.stderr.trim())
        }
        if (gdbRes.exitCode != 0 && !gdbRes.stdout?.trim() && !gdbRes.stderr?.trim()) {
            log.error('gdb failed with exit code {} (no output)', gdbRes.exitCode)
        }
    }
}
