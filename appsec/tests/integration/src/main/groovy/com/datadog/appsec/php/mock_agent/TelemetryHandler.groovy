package com.datadog.appsec.php.mock_agent

import groovy.json.JsonSlurper
import groovy.util.logging.Slf4j
import io.javalin.http.BadRequestResponse
import io.javalin.http.Context
import io.javalin.http.Handler
import org.jetbrains.annotations.NotNull

@Singleton
@Slf4j
class TelemetryHandler implements Handler {

    private final static JsonSlurper jsonSlurper = new JsonSlurper()
    final List<Object> capturedTelemetryMessages = []
    AssertionError savedError

    @Override
    void handle(@NotNull Context ctx) throws Exception {
        Object message
        AssertionError error
        try {
            ctx.bodyInputStream().withCloseable {
                message = readTelemetryMessage(it)
            }
            log.debug("Read telemetry message: ${message['request_type']}")
        } catch (AssertionError e) {
            log.error("Error reading traces: $e.message")
            error = e
        }

        // response
        ctx.contentType('text/plain')
                .result('')

        if (message) {
            synchronized (capturedTelemetryMessages) {
                capturedTelemetryMessages.add message
                capturedTelemetryMessages.notify()
            }
        }
        if (error) {
            synchronized (capturedTelemetryMessages) {
                savedError = error
                capturedTelemetryMessages.notify()
                throw new BadRequestResponse(error.message)
            }
        }
    }

    private static Object readTelemetryMessage(InputStream is) {
        jsonSlurper.parse(is)
    }

    List<Object> drain(long timeoutInMs) {
        synchronized (capturedTelemetryMessages) {
            if (!savedError && capturedTelemetryMessages.isEmpty()) {
                capturedTelemetryMessages.wait(timeoutInMs)
            }
            if (savedError) {
                def e = savedError
                savedError = null
                throw e
            }
            def messages = [*capturedTelemetryMessages]
            capturedTelemetryMessages.clear()
            messages
        }
    }
}
