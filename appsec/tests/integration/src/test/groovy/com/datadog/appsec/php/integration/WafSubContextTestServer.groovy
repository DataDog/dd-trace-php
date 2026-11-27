package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import groovy.json.JsonSlurper
import org.testcontainers.lifecycle.Startable

class WafSubContextTestServer implements Startable {
    // The endpoint appends one JSON line per request it serves to this file
    // (see log_request() in curl_requests_endpoint.php)
    static final String REQUEST_LOG = '/tmp/curl_endpoint_requests.log'

    private static int sentinelCounter = 0

    private final int port
    private AppSecContainer container

    WafSubContextTestServer(AppSecContainer container,  int port) {
        this.port = port
        this.container = container
    }

    @Override
    void start() {
        // Start PHP server without ddtrace inside the container
        container.execInContainer('bash', '-c',
                "php -n -d enable_post_data_reading=Off -d memory_limit=256M " +
                        "-d curl_endpoint.log=${REQUEST_LOG} " +
                        "-S 127.0.0.1:${port} -t " +
                        "/var/www/public > /tmp/test_server.log 2>&1 &")

        // Wait for the server to be ready: curl retries the ping while the
        // connection is refused, so the test has no sleep of its own (G10.8)
        def result = container.execInContainer('curl', '-s', '-m', '2',
                '--retry', '30', '--retry-delay', '1', '--retry-connrefused',
                "http://127.0.0.1:${port}/curl_requests_endpoint.php?variant=ping")
        if (result.getStdout().trim() != 'pong') {
            throw new RuntimeException("Test server failed to start on port ${port}: " +
                    "${result.getStdout()} ${result.getStderr()}")
        }
    }

    // Forget what the endpoint has logged so far
    static void reset(AppSecContainer container) {
        def res = container.execInContainer('rm', '-f', REQUEST_LOG)
        assert res.exitCode == 0
    }

    // The requests the endpoint has served since the last reset, oldest first:
    // maps with rid, variant, uri, method, content_length, transfer_encoding,
    // body_length, body_sha1 and body (decoded from the body_b64 of the line)
    static List<Map> received(AppSecContainer container) {
        def res = container.execInContainer('bash', '-c',
                "cat ${REQUEST_LOG} 2>/dev/null || true")
        def slurper = new JsonSlurper()
        res.stdout.readLines().findAll { !it.isEmpty() }.collect {
            Map entry = new LinkedHashMap(slurper.parseText(it) as Map)
            assert !entry.containsKey('error') : "the endpoint could not log a request: ${entry.error}"
            // the bytes of the body, one character per byte
            entry.body = new String((entry.remove('body_b64') as String).decodeBase64(), 'ISO-8859-1')
            entry
        }
    }

    /**
     * Runs the check on the log only once the log has caught up with the
     * requests sent before it (G10.10). php -S services ready descriptors in
     * descriptor order, so the request that was last sent may be served after
     * a later one: two sentinel pings are sent, the second after the response
     * to the first, and when both are in the log, every request that had been
     * completely received before the first was sent is in it, too. The
     * entries are those of the log, without the sentinels.
     */
    static List<Map> receivedAfterSentinels(AppSecContainer container, int port = 8899) {
        int n = ++sentinelCounter
        def markers = ["sentinel-${n}-a", "sentinel-${n}-b"]
        markers.each { marker ->
            def res = container.execInContainer('curl', '-s', '-m', '5',
                    "http://127.0.0.1:${port}/curl_requests_endpoint.php?variant=ping&rid=${marker}")
            assert res.stdout.trim() == 'pong' : "sentinel ${marker} got no pong: ${res.stdout} ${res.stderr}"
        }
        def all = received(container)
        markers.each { marker ->
            assert all.any { it.rid == marker } : "sentinel ${marker} is missing from the log: ${all*.uri}"
        }
        all.findAll { !it.rid.startsWith('sentinel-') }
    }

    @Override
    void stop() {
        // PHP server will be stopped when container is stopped
    }
}
