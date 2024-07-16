package com.datadog.appsec.php.mock_agent

import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigResponse
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.fasterxml.jackson.databind.ObjectMapper
import com.google.common.collect.Lists
import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import io.javalin.http.Context
import io.javalin.http.Handler
import org.jetbrains.annotations.NotNull

@Slf4j
@CompileStatic
@Singleton
class ConfigV07Handler implements Handler {
    RemoteConfigResponse nextResponse
    final List<RemoteConfigRequest> capturedRequests = []

    @Override
    void handle(@NotNull Context context) throws Exception {
        RemoteConfigRequest request = context.bodyStreamAsClass(RemoteConfigRequest)
        log.debug("Received request with version ${request.client.clientState.targetsVersion}: {}", request.toString())
        synchronized (capturedRequests) {
            capturedRequests.add(request)
            capturedRequests.notify()
        }
        if (nextResponse != null) {
            context.json(nextResponse)
        } else {
            context.json([:])
        }
    }

    void setNextResponse(RemoteConfigResponse nextResponse) {
        this.nextResponse = nextResponse
    }

    List<RemoteConfigRequest> drain(long timeoutInMs) {
        synchronized (capturedRequests) {
            if (capturedRequests.isEmpty()) {
                capturedRequests.wait(timeoutInMs)
            }
            def requests = Lists.newArrayList(capturedRequests)
            capturedRequests.clear()
            requests
        }
    }
}
