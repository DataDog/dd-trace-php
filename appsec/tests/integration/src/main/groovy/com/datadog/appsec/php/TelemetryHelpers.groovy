package com.datadog.appsec.php

class TelemetryHelpers {
   static <T> List<T> filterMessages(List<Map> telemetryData, Class<T> type) {
        telemetryData.findAll { it['request_type'] == type.name } +
                telemetryData.findAll { it['request_type'] == 'message-batch'}
                        *.payload*.findAll { it['request_type'] == 'generate-metrics' }.flatten()
        .collect { type.newInstance([it['payload']] as Object[]) }
   }

    static class GenerateMetrics {
        static name = 'generate-metrics'
        List<Metric> series

        GenerateMetrics(Map m) {
            series = m.series.collect { new Metric(it as Map) }
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
}
