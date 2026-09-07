package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.MethodOrderer
import org.junit.jupiter.api.Order
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestMethodOrder
import org.junit.jupiter.api.condition.EnabledIf
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString

@Testcontainers
@Slf4j
@EnabledIf('isZts84')
@TestMethodOrder(MethodOrderer.OrderAnnotation)
class ConnectivityTests {
    static boolean zts84 = variant.contains('zts') && phpVersion.contains('8.4')

    @Container
    @FailOnUnmatchedTraces
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
                    withEnv('DD_APPSEC_TESTING_INVALID_COMMAND', '1')
                }
            }

    static void main(String[] args) {
        InspectContainerHelper.run(CONTAINER)
    }

    /**
     * Verifies that helper clients exit promptly (within 5 seconds) when the
     * sidecar sends disconnect notifications, rather than waiting for the
     * cancellation token (~60 s timeout).
     *
     * SIGHUP to the Apache parent is an *ungraceful* restart: workers are killed
     * before PHP GSHUTDOWN runs, so the sidecar detects the socket drop and sends
     * disconnect notifications. Clients must exit via ForcefulDisconnect promptly.
     *
     * Regression test for two bugs:
     * 1. The Client struct held its own mpsc::Sender, keeping the channel alive
     *    after CLIENTS dropped its copy, so the receiver never saw EOF.
     * 2. The session-wide sweep (client_id=0) held the CLIENTS mutex while
     *    calling remove_client_bookkeeping, causing a deadlock.
     */
    @Test
    @Order(1)
    void 'helper clients exit promptly on sidecar disconnect notification'() {
        // Make 2 requests so 2 helper clients are created and waiting for work.
        CONTAINER.traceFromRequest('/hello.php') { }
        CONTAINER.traceFromRequest('/hello.php') { }

        // SIGHUP = ungraceful restart: workers are killed without PHP teardown.
        // The sidecar detects socket closure and sends per-client disconnect notifications.
        def hupResult = CONTAINER.execInContainer(
                'sh', '-c', 'kill -HUP $(pgrep -f /usr/sbin/apache2 | head -1)')
        assert hupResult.exitCode == 0: "apache2 HUP failed: ${hupResult.stderr}"

        // Poll helper.log for up to 5 seconds waiting for both clients to exit.
        // A correct fix causes them to exit within ~100 ms; 5 s is generous.
        String helperLog = ''
        long deadline = System.currentTimeMillis() + 5_000
        while (System.currentTimeMillis() < deadline) {
            helperLog = CONTAINER.execInContainer('cat', '/tmp/logs/helper.log').stdout
            if (helperLog.count('ended due to client connectivity issue') >= 2) {
                break
            }
            Thread.sleep(200)
        }

        log.info('helper.log after HUP:\n{}',
                helperLog.readLines()
                        .findAll { it =~ /Disconnect|ended|Cancellation|Created client/ }
                        .join('\n'))

        int disconnectedExits = helperLog.count('ended due to client connectivity issue')
        assert disconnectedExits >= 2:
                "Expected >=2 clients to exit via connectivity issue within 5 s, got $disconnectedExits.\n" +
                "This likely means the channel was not closed by the disconnect notification."

        assert !helperLog.contains('Cancellation during client recv'):
                "Clients exited via cancellation token rather than disconnect notification — " +
                "the channel was not properly closed on disconnect."
    }

    /**
     * Verifies that a helper client exits cleanly ("ended normally") when the
     * Apache worker sends a client_shutdown goodbye via PHP GSHUTDOWN.
     *
     * MaxConnectionsPerChild 1 causes the worker to call clean_child_exit after
     * a single TCP connection, which destroys the APR child pool, triggers
     * PHP MSHUTDOWN + GSHUTDOWN, and sends client_shutdown through the sidecar.
     * helper-rust receives it and exits via CleanShutdown.
     *
     */
    @Test
    @Order(2)
    void 'helper client exits cleanly when PHP GSHUTDOWN sends client_shutdown'() {
        // Back up the event MPM config so we can restore it after the test.
        def backupResult = CONTAINER.execInContainer('sh', '-c',
                'cp /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.conf.backup')
        assert backupResult.exitCode == 0: "config backup failed: ${backupResult.stderr}"

        try {
            int cleanBefore = CONTAINER.execInContainer('cat', '/tmp/logs/helper.log')
                    .stdout.count('ended normally')

            def cfgResult = CONTAINER.execInContainer('sh', '-c',
                    'OLD=$(pgrep -P $(pgrep -f /usr/sbin/apache2 | head -1))' +
                    ' && echo "MaxConnectionsPerChild 1\nKeepAlive Off" >> /etc/apache2/mods-enabled/mpm_event.conf' +
                    ' && apache2ctl restart' +
                    ' && for p in $OLD; do while kill -0 $p 2>/dev/null; do sleep 0.05; done; done')
            assert cfgResult.exitCode == 0: "apache2 config failed: ${cfgResult.stderr}"

            CONTAINER.traceFromRequest('/hello.php', { HttpResponse<String> resp ->
                assert resp.statusCode() == 200
            })


            String helperLog = ''
            long deadline = System.currentTimeMillis() + 15_000
            while (System.currentTimeMillis() < deadline) {
                helperLog = CONTAINER.execInContainer('cat', '/tmp/logs/helper.log').stdout
                if (helperLog.count('ended normally') > cleanBefore) {
                    break
                }
                Thread.sleep(300)
            }

            assert helperLog.count('ended normally') > cleanBefore:
                    "Expected client to exit cleanly (client_shutdown/GSHUTDOWN) within 10 s.\n" +
                    "MaxConnectionsPerChild 1 should trigger PHP GSHUTDOWN which sends client_shutdown."

            assert !helperLog.contains('Cancellation during client recv'):
                    "Client exited via cancellation token rather than GSHUTDOWN."
        } finally {
            CONTAINER.execInContainer('sh', '-c',
                    'cp /etc/apache2/mods-enabled/mpm_event.conf.backup' +
                    ' /etc/apache2/mods-enabled/mpm_event.conf' +
                    ' && apache2ctl restart')
        }
    }

    /**
     * Verifies that the extension retries the sidecar connection on RINIT after
     * the sidecar process is killed with SIGKILL, and that WAF blocking still works.
     *
     * A "blocking request" is one that the WAF blocks (returns 403). With only 1
     * worker/process, we can be sure each request goes through the same sidecar
     * client. The test:
     * 1. Sends a WAF-blocking request — verifies it is blocked (403).
     * 2. Kills the sidecar with SIGKILL.
     * 3. Sends the same blocking request again — it must also be blocked (403),
     *    proving RINIT retried the sidecar connection and the WAF rules are active.
     */
    @Test
    @Order(3)
    void 'WAF blocking still works after sidecar is killed and reconnected on RINIT'() {
        def backupResult = CONTAINER.execInContainer('sh', '-c',
                'cp /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.conf.bak3')
        assert backupResult.exitCode == 0: "backup failed: ${backupResult.stderr}"

        try {
            // Ensure a single worker/process so each request uses the same sidecar client.
            def cfgResult = CONTAINER.execInContainer('sh', '-c',
                    'printf "MaxRequestWorkers 1\nThreadsPerChild 1\n"' +
                    ' >> /etc/apache2/mods-enabled/mpm_event.conf && apache2ctl restart')
            assert cfgResult.exitCode == 0: "apache2 config failed: ${cfgResult.stderr}"

            def blockingReq = {
                CONTAINER.buildReq('/phpinfo.php')
                        .header('X-Forwarded-For', '80.80.80.80').GET().build()
            }

            // First blocking request: verify the WAF is working.
            // Use ignoreOtherRequests=true in case the liveness-check trace is still in flight.
            def trace1 = CONTAINER.traceFromRequest(blockingReq(), ofString(), { HttpResponse<String> resp ->
                assert resp.statusCode() == 403: "Expected WAF to block the first request"
            }, true)
            assert trace1.first().meta.'appsec.blocked' == 'true'

            // Kill the sidecar with SIGKILL — simulates an unexpected crash.
            def killResult = CONTAINER.execInContainer(
                    'sh', '-c', 'kill -9 $(pgrep -f datadog-ipc-helper | head -1)')
            assert killResult.exitCode == 0: "sidecar kill failed: ${killResult.stderr}"

            // Second blocking request: RINIT must retry the sidecar connection so the
            // WAF rules are reloaded and the request is blocked again.
            def trace2 = CONTAINER.traceFromRequest(blockingReq(), ofString(), { HttpResponse<String> resp ->
                assert resp.statusCode() == 403: "Expected WAF to block after sidecar reconnect"
            }, true)
            assert trace2.first().meta.'appsec.blocked' == 'true'
        } finally {
            CONTAINER.execInContainer('sh', '-c',
                    'cp /etc/apache2/mods-enabled/mpm_event.conf.bak3' +
                    ' /etc/apache2/mods-enabled/mpm_event.conf && apache2ctl restart')
        }
    }

    /**
     * Verifies that WAF blocking still works after the extension sends an invalid
     * command that forces the helper to disconnect the client.
     *
     * send_invalid_msg.php calls \datadog\appsec\testing\send_invalid_msg() which
     * sends an unrecognised command to helper-rust. The helper responds with a
     * FatalError (disconnect: true) and the client task exits. On the next request's
     * RINIT the extension must reconnect and reload the WAF so that a blocking
     * request still gets blocked (403).
     *
     * Requires DD_APPSEC_TESTING_INVALID_COMMAND=1 (set on the container).
     */
    @Test
    @Order(4)
    void 'WAF blocking still works after invalid command disconnects the helper client'() {
        def backupResult = CONTAINER.execInContainer('sh', '-c',
                'cp /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.conf.bak4')
        assert backupResult.exitCode == 0: "backup failed: ${backupResult.stderr}"

        try {
            // Standalone config: single worker (no MaxConnectionsPerChild so all
            // requests share the same worker and connection).
            def cfgResult = CONTAINER.execInContainer('sh', '-c',
                    'printf "MaxRequestWorkers 1\nThreadsPerChild 1\n"' +
                    ' >> /etc/apache2/mods-enabled/mpm_event.conf && apache2ctl restart')
            assert cfgResult.exitCode == 0: "apache2 config failed: ${cfgResult.stderr}"


            // Warmup: establish the sidecar connection on this request so that
            // send_invalid_msg runs on a request that REUSES the existing connection.
            // dd_helper_close_conn only triggers the reconnection backoff when the
            // connection was opened on the same request (connected_this_req == true).
            // By warming up first, connected_this_req is false for send_invalid_msg.
            CONTAINER.traceFromRequest('/hello.php', null, true)

            // Send the invalid command to force a helper client disconnect (no backoff
            // because the connection was already established by the warmup request).
            CONTAINER.traceFromRequest('/send_invalid_msg.php') { HttpResponse<InputStream> resp ->
                assert resp.statusCode() == 200: "send_invalid_msg.php must return 200"
            }

            // The helper client exited. The next request's RINIT must reconnect and
            // reload WAF rules so that a blocking request is still blocked (403).
            HttpRequest blockingReq = CONTAINER.buildReq('/phpinfo.php')
                    .header('X-Forwarded-For', '80.80.80.80').GET().build()
            def trace = CONTAINER.traceFromRequest(blockingReq, ofString(), { HttpResponse<String> resp ->
                assert resp.statusCode() == 403: "Expected WAF to block after invalid-command reconnect"
            }, true)
            assert trace.first().meta.'appsec.blocked' == 'true'
        } finally {
            CONTAINER.execInContainer('sh', '-c',
                    'cp /etc/apache2/mods-enabled/mpm_event.conf.bak4' +
                    ' /etc/apache2/mods-enabled/mpm_event.conf && apache2ctl ')
        }
    }
}
