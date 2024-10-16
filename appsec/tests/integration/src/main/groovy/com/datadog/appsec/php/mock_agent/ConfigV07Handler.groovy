package com.datadog.appsec.php.mock_agent

import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigResponse
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
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
    private final Map<Target, RemoteConfigResponse> nextResponse = [:]
    final Map<Target, RemoteConfigRequest> capturedRequests = [:]

    @Override
    void handle(@NotNull Context context) throws Exception {
        RemoteConfigRequest request = context.bodyStreamAsClass(RemoteConfigRequest)
        log.debug("Received request with version ${request.client.clientState.targetsVersion}: {}", request.toString())
        Target target = request.extractTarget()
        RemoteConfigResponse resp
        synchronized (this) {
            resp = nextResponse[target]
            nextResponse[target] = (RemoteConfigResponse) null
        }
        synchronized (capturedRequests) {
            capturedRequests[target] = request
            capturedRequests.notify()
        }
        if (resp != null) {
            log.info("Sending RC response for {}, req targets version={}, resp targets version={}",
                    target, request.client.clientState.targetsVersion, resp.targetsSigned.version)
            context.json(resp)
        } else {
            context.json([:])
        }
    }

    void setNextResponse(Target target, RemoteConfigResponse nextResponse) {
        synchronized (this) {
            this.nextResponse[target] = nextResponse
        }
    }

    RemoteConfigRequest waitForVersion(Target target, long version, long timeoutInMs) {
        if (timeoutInMs <= 5) {
            synchronized (capturedRequests) {
                RemoteConfigRequest request = capturedRequests[target]
                if (request != null && request.client.clientState.targetsVersion == version) {
                    return request
                }
            }
            throw new AssertionError("No request with version $version is stord at this point" as Object)
        }

        log.debug("waitForVersion start")
        long deadline = System.currentTimeMillis() + timeoutInMs
        synchronized (capturedRequests) {
            while (System.currentTimeMillis() < deadline) {
                RemoteConfigRequest request = capturedRequests[target]
                if (request != null && request.client.clientState.targetsVersion == version) {
                    return request
                }
                capturedRequests.wait(deadline - System.currentTimeMillis())
            }
        }
        log.debug("waitForVersion timeout")
        throw new AssertionError("No request with version $version gotten within " +
                "$timeoutInMs ms for $target" as Object)
    }

    List<Map<Target,RemoteConfigRequest>> drain(long timeoutInMs) {
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
