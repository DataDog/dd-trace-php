package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import com.datadog.appsec.php.docker.FailOnUnmatchedTraces
import com.datadog.appsec.php.docker.InspectContainerHelper
import com.datadog.appsec.php.docker.LogFile
import com.datadog.appsec.php.model.Trace
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.Tag
import org.junit.jupiter.api.condition.DisabledIf
import org.junit.jupiter.api.Test
import org.testcontainers.junit.jupiter.Container
import org.testcontainers.junit.jupiter.Testcontainers

import java.net.http.HttpRequest
import java.net.http.HttpResponse

import static com.datadog.appsec.php.integration.TestParams.getPhpVersion
import static com.datadog.appsec.php.integration.TestParams.getTracerVersion
import static com.datadog.appsec.php.integration.TestParams.getVariant
import static java.net.http.HttpResponse.BodyHandlers.ofString
import static org.testcontainers.containers.Container.ExecResult

@Testcontainers
@Slf4j
@Tag('zts-musl')
class Apache2ModTests implements CommonTests {
    static boolean zts = variant.contains('zts')

    @Container
    @FailOnUnmatchedTraces
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
    void 'trace without attack after soft restart'() {
        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> it ->
            assert it.body().text == 'Hello world!'
        }
        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d

        List<String> reloadCommand = variant.contains('musl') ?
                ['httpd', '-k', 'graceful'] :
                ['service', 'apache2', 'reload']
        ExecResult res = CONTAINER.execInContainer(*reloadCommand)
        if (res.exitCode != 0) {
            throw new AssertionError("Failed reloading apache2: $res.stderr")
        }
        log.info "Result of restart: STDOUT: $res.stdout , STDERR: $res.stderr"

        trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> it ->
            assert it.body().text == 'Hello world!'
        }
        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d
    }

    @Test
    void 'request recovery preserves state and closes unfinished clients cleanly'() {
        // PHP skips RSHUTDOWN after a failed startup, so a request blocked in
        // AppSec's request init runs AppSec's request shutdown by hand. The
        // next request on the same worker must not take that request for
        // one whose shutdown was skipped.
        def countStale = { ->
            CONTAINER.execInContainer('grep', '-c',
                    "AppSec's shutdown did not run", '/tmp/logs/appsec.log')
                    .stdout.trim() as int
        }
        int before = countStale()

        HttpRequest req = CONTAINER.buildReq('/hello.php')
                .header('X-Forwarded-For', '80.80.80.80').GET().build()
        CONTAINER.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
        }

        Trace trace = CONTAINER.traceFromRequest('/hello.php') { HttpResponse<InputStream> it ->
            assert it.body().text == 'Hello world!'
        }
        assert trace.first().metrics."_dd.appsec.enabled" == 1.0d
        assert countStale() == before

        def helperLog = new LogFile(CONTAINER, 'helper.log')
        helperLog.markEndPos()

        // Start another helper request without finishing the first, then send
        // a blocked IP to check that the replacement client still runs the WAF.
        def blocked = CONTAINER.traceFromRequest('/restart_request.php', ofString()) {
            HttpResponse<String> resp ->
                assert resp.statusCode() == 403
        }
        assert blocked.first().meta.'appsec.blocked' == 'true'

        List<String> lines = []
        List<String> clients = []
        long deadline = System.currentTimeMillis() + 5_000
        while (System.currentTimeMillis() < deadline) {
            lines = helperLog.linesSinceMark
            clients = lines.findResults { line ->
                def match = line =~ /Client #(\d+): Received command: RequestInit/
                match.find() ? match.group(1) : null
            }
            if (clients.size() == 2 &&
                    lines.any { it.contains("Client #${clients[0]}: ended normally") }) {
                break
            }
            Thread.sleep(100)
        }

        assert clients.size() == 2 : 'Expected both requests to reach the helper'
        assert clients[0] != clients[1] : 'Expected a new helper client'
        String oldClient = "Client #${clients[0]}: "
        assert lines.any { it.contains(oldClient + 'client_shutdown received (clean)') } :
                'The old client did not receive a clean goodbye'
        assert lines.any { it.contains(oldClient + 'ended normally') } :
                'The old client did not exit normally'
        assert !lines.any { it.contains('replaces its running client') }
        assert !lines.any { it.contains(oldClient + 'ended due to client connectivity issue') }
    }

    @Test
    void 'test authorization header'() {
        //This test is only on Apache2mod because it requires to configure webserver to pass down authorization headers
        HttpRequest req = container.buildReq('/phpinfo.php')
                .header('Authorization', 'digest 1234567890').GET().build()
        def trace = container.traceFromRequest(req, ofString()) { HttpResponse<String> re ->
            assert re.statusCode() == 403
            assert re.body().contains('blocked')
        }
    }
}
