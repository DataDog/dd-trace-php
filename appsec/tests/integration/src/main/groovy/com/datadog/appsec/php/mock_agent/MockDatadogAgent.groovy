package com.datadog.appsec.php.mock_agent

import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigRequest
import com.datadog.appsec.php.mock_agent.rem_cfg.RemoteConfigResponse
import com.datadog.appsec.php.mock_agent.rem_cfg.Target
import com.datadog.appsec.php.model.Trace
import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import io.javalin.Javalin
import org.testcontainers.lifecycle.Startable

@Slf4j
@CompileStatic
class MockDatadogAgent implements Startable {
    boolean started
    Javalin httpServer

    TracesV04Handler tracesHandler = TracesV04Handler.instance

    @Override
    void start() {
        this.httpServer = Javalin.create(config -> {
            config.showJavalinBanner = false
        })
        started = true

        this.httpServer.post('v0.4/traces', tracesHandler)
        this.httpServer.put('v0.4/traces', tracesHandler)
        this.httpServer.get('info', InfoHandler.instance)
        this.httpServer.post('/telemetry/proxy/api/v2/apmtelemetry', TelemetryHandler.instance)
        this.httpServer.post('v0.7/config', ConfigV07Handler.instance)
        this.httpServer.error(404, ctx -> {
            log.info("Unmatched request: ${ctx.method()} ${ctx.path()}")
        })

        this.httpServer.start(0)
    }

    int getPort() {
        this.httpServer.port()
    }

    @Override
    void stop() {
        // some problem here. It seems jetty threads are still live after stop() is called
        this.httpServer.stop()
        started = false
    }

    static void main(String[] args) {
        def ddAgent = new MockDatadogAgent()
        ddAgent.start()
        try {
            while (true) {
                println ddAgent.nextTrace(3600000)
            }
        } catch (InterruptedException ie) {
            System.err.println 'Exiting'
            ddAgent.stop()
        }
    }

    Trace nextTrace(int timeoutInMs) {
        tracesHandler.nextTrace(timeoutInMs)
    }

    List<Object> drainTraces() {
        tracesHandler.drainTraces()
    }

    List<Object> drainTelemetry(int timeoutInMs) {
        TelemetryHandler.instance.drain(timeoutInMs)
    }

    void setNextRCResponse(Target target, RemoteConfigResponse nextResponse) {
        ConfigV07Handler.instance.setNextResponse(target, nextResponse)
    }

    RemoteConfigRequest waitForRCVersion(Target target, long version, long timeoutInMs) {
        ConfigV07Handler.instance.waitForVersion(target, version, timeoutInMs)
    }
}
