package com.datadog.appsec.php.integration

import com.datadog.appsec.php.docker.AppSecContainer
import org.testcontainers.lifecycle.Startable

class WafSubContextTestServer implements Startable {
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
                "php -n -d enable_post_data_reading=Off -S 127.0.0.1:${port} -t " +
                        "/var/www/public > /tmp/test_server.log 2>&1 &")

        // Wait for server to be ready by pinging it
        def maxAttempts = 50
        for (int i = 0; i < maxAttempts; i++) {
            try {
                def result = container.execInContainer('curl', '-s', '-m', '1',
                        "http://127.0.0.1:${port}/curl_requests_endpoint.php?variant=ping")
                if (result.getStdout().trim() == 'pong') {
                    return
                }
            } catch (Exception ignored) {
            }
            Thread.sleep(100)
        }

        throw new RuntimeException("Test server failed to start on port ${port}")
    }

    @Override
    void stop() {
        // PHP server will be stopped when container is stopped
    }
}
