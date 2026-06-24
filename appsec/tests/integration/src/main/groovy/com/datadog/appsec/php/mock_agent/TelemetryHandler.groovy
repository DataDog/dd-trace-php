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
            log.debug("Read telemetry message: ${describeTelemetryMessage(message)}")
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

    private static String describeTelemetryMessage(Object message) {
        def application = message['application'] ?: [:]
        def requestType = message['request_type']
        def payload = message['payload']
        def details = [
                "request_type=${requestType}",
                "seq_id=${message['seq_id']}",
                "service=${application['service_name']}",
                "runtime_id=${application['runtime_id']}",
        ]
        def payloadSummary = describeTelemetryPayload(requestType, payload)
        if (payloadSummary) {
            details << "payload=${payloadSummary}"
        }
        details.join(', ')
    }

    private static String describeTelemetryPayload(String requestType, Object payload) {
        if (requestType == 'message-batch' && payload instanceof List) {
            return payload.collect { describeTelemetryPayload(it['request_type'], it['payload']) }
                    .findAll { it }
                    .join('; ')
        }

        if (!(payload instanceof Map)) {
            return null
        }

        def fields = []
        if (payload['integrations'] instanceof List) {
            fields << "integrations=${payload['integrations'].collect { it['name'] }}"
        }
        if (payload['dependencies'] instanceof List) {
            fields << "dependencies=${payload['dependencies'].size()}"
        }
        if (payload['configuration'] instanceof List) {
            fields << "configuration=${payload['configuration'].size()}"
        }

        return "${requestType}{${fields.join(', ')}}"
    }

    List<Object> drain(long timeoutInMs) {
        synchronized (capturedTelemetryMessages) {
            if (!savedError && capturedTelemetryMessages.isEmpty()) {
                if (timeoutInMs != 0) {
                    capturedTelemetryMessages.wait(timeoutInMs)
                }
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
