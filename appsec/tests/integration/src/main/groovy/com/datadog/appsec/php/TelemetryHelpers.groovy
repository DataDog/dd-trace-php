package com.datadog.appsec.php

import groovy.transform.Canonical

/**
 * @link https://github.com/DataDog/instrumentation-telemetry-api-docs/blob/main/GeneratedDocumentation/ApiDocs/v2/producing-telemetry.md
 */
class TelemetryHelpers {
   static <T> List<T> filterMessages(List<Map> telemetryData, Class<T> type) {
       (telemetryData.findAll { it['request_type'] in type.names } +
                telemetryData.findAll { it['request_type'] == 'message-batch'}
                        *.payload*.findAll { it['request_type'] in type.names }.flatten())
        .collect { type.newInstance([it['payload']] as Object[]) }
   }

    static class GenerateMetrics {
        static names = ['generate-metrics']
        List<Metric> series

        GenerateMetrics(Map m) {
            series = m.series.collect { new Metric(it as Map) }
        }
    }

    static class AppEndpoints {
        static names = ['app-endpoints']
        List<Endpoint> endpoints

        AppEndpoints(Map m) {
            endpoints = m.endpoints.collect { new Endpoint(it as Map) }
        }
    }

    static class Endpoint {
        String method
        String operationName
        String path
        String resourceName

        Endpoint(Map m) {
            method = m.method
            operationName = m.operation_name
            path = m.path
            resourceName = m.resource_name
        }
    }

    static class Metric {
        String namespace
        String name
        List<Object> points
        List<String> tags
        boolean common
        String type
        long interval

        Metric(Map m) {
            namespace = m.namespace
            name = m.metric
            points = m.points
            tags = m.tags
            common = m.common
            type = m.type
            interval = m.interval
        }
    }

    static class Logs {
        static names = ['logs']
        List<Log> logs

        Logs(List m) {
            logs = m.collect { new Log(it as Map) }
        }
    }

    @Canonical
    static class Log {
        String level
        String message
        int count
        String tags
        String stack_trace
        String is_sensitive
        boolean is_crash

        def getParsedTags() {
            if (tags) {
                tags.split(',').collectEntries { tag ->
                    def parts = tag.split(':', 2)
                    [(parts[0]): parts[1]]
                }
            } else {
                [:]
            }
        }
    }

    static class WithConfiguration {
        static names = ['app-started', 'app-client-configuration-change']
        List<ConfigurationEntry> configuration

        WithConfiguration(Map m) {
            configuration = m.configuration?.collect { new ConfigurationEntry(it) }
        }
    }

    static class WithDependencies {
        static names = ['app-started', 'app-dependencies-loaded']
        List<DependencyEntry> dependencies

        WithDependencies(Map m) {
            dependencies = m.dependencies?.collect { new DependencyEntry(it) }
        }
    }

    static class WithIntegrations {
        static names = ['app-started', 'app-integrations-change']
        List<IntegrationEntry> integrations

        WithIntegrations(Map m) {
            integrations = m.integrations?.collect { new IntegrationEntry(it) }
        }
    }

    static class ConfigurationEntry {
        String name
        String value
        String origin

        ConfigurationEntry(Map m) {
            name = m.name
            value = m.value
            origin = m.origin
        }
    }

    static class DependencyEntry {
        String name
        String version

        DependencyEntry(Map m) {
            name = m.name
            version = m.version
        }
    }

    static class IntegrationEntry {
        String name
        Boolean enabled
        String version
        Boolean compatible
        Boolean autoEnabled

        IntegrationEntry(Map m) {
            name = m.name
            enabled = m.enabled
            version = m.version
            compatible = m.compatible
            autoEnabled = m.autoEnabled
        }
    }
}
