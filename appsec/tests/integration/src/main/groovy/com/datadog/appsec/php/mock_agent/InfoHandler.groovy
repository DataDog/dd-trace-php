package com.datadog.appsec.php.mock_agent

import io.javalin.http.Context
import io.javalin.http.Handler
import org.jetbrains.annotations.NotNull

@Singleton
class InfoHandler implements Handler {
    @Override
    void handle(@NotNull Context ctx) throws Exception {
        ctx.json(InfoResponse.instance)
    }

    @Singleton
    static class InfoResponse {
        String version = '7.49.0'
        List<String> endpoints = [
                '/v0.4/traces',
                '/v0.7/config',
        ]
    }

}
