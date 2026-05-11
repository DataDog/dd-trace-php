package com.datadog.appsec.php

import groovy.transform.Canonical
import groovy.transform.ToString
import groovy.transform.stc.ClosureParams
import groovy.transform.stc.FromString
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.util.Base64
import java.util.TreeMap
import com.datadog.appsec.php.docker.AppSecContainer
import static java.net.http.HttpResponse.BodyHandlers.ofString

/**
 * @link https://github.com/DataDog/instrumentation-telemetry-api-docs/blob/main/GeneratedDocumentation/ApiDocs/v2/producing-telemetry.md
 */
class TelemetryHelpers {
    /**
     * Filters drained telemetry to messages of the given wrapper type, unwrapping any
     * {@code message-batch} envelopes.
     *
     * <p>The sidecar runs two telemetry workers per session: one for the host app and
     * one for {@code service_name == "datadog-ipc-helper"} (its own self-telemetry).
     * Tests almost always want only the former; pass {@code userAppOnly = false} to
     * include the sidecar's own telemetry too.
     */
    static <T> List<T> filterMessages(List<Map> telemetryData, Class<T> type, boolean userAppOnly = true) {
        List<Map> payloads = []
        for (msg in telemetryData) {
            if (userAppOnly && msg.application?.service_name == 'datadog-ipc-helper') continue
            if (msg.request_type in type.names) {
                payloads << (msg.payload as Map)
            } else if (msg.request_type == 'message-batch') {
                for (inner in (msg.payload as List)) {
                    if (inner.request_type in type.names) {
                        payloads << (inner.payload as Map)
                    }
                }
            }
        }
        payloads.collect { type.newInstance([it] as Object[]) }
    }

    static class GenerateMetrics {
        static names = ['generate-metrics']
        List<Metric> series

        GenerateMetrics(Map m) {
            series = m.series.collect { new Metric(it as Map) }
        }
    }

    static class GenerateDistributions {
        static names = ['sketches']
        List<DistributionMetric> series

        GenerateDistributions(Map m) {
            series = m.series.collect { new DistributionMetric(it as Map) }
        }
    }

    static class DistributionMetric {
        String namespace
        String name
        List<String> tags
        double count
        double zeroCount
        TreeMap<Integer, Double> bins  // bin index → count, non-zero only
        double gamma = Double.NaN
        double indexOffset = 0.0

        DistributionMetric(Map m) {
            namespace = m.namespace
            name = m.metric
            tags = m.tags
            bins = new TreeMap<>()
            SketchDecoder.decode(m.sketch_b64 as String, this)
        }

        double binLower(int k) { Math.exp((k - indexOffset) * Math.log(gamma)) }
        double binUpper(int k) { binLower(k) * gamma }

        /** Returns the count of the bin whose interval contains {@code value}, or null if none. */
        Double countForBinContaining(double value) {
            bins.entrySet().find { entry ->
                int k = entry.key as int
                binLower(k) <= value && value < binUpper(k)
            }?.value as Double
        }

        @Override
        String toString() {
            def allBins = []
            if (zeroCount != 0.0) allBins << String.format("'0': %.4g", zeroCount)
            bins.each { k, c -> allBins << String.format("'%.4g..%.4g': %.4g", binLower(k), binUpper(k), c) }
            "DistributionMetric[${namespace}, ${name}, ${tags}, count=${count}, bins={${allBins.join(', ')}}]"
        }
    }

    /**
     * Protobuf decoder for the DDSketch wire format.
     *
     * DDSketch proto (see libdd-ddsketch/src/pb.rs):
     *   message DdSketch {
     *     IndexMapping mapping          = 1;
     *     Store        positive_values  = 2;
     *     Store        negative_values  = 3;
     *     double       zero_count       = 4;
     *   }
     *   message IndexMapping {
     *     double gamma        = 1;
     *     double index_offset = 2;
     *   }
     *   message Store {
     *     map<sint32,double> bin_counts                 = 1;
     *     repeated double    contiguous_bin_counts      = 2; // packed
     *     sint32             contiguous_bin_index_offset= 3;
     *   }
     * Bin index k represents the interval [gamma^(k-offset), gamma^(k-offset+1)).
     * See libdd-ddsketch/src/lib.rs LogMapping::value() for the representative value formula.
     */
    private static class SketchDecoder {
        private final java.nio.ByteBuffer buf
        private final DistributionMetric metric

        private SketchDecoder(byte[] bytes, DistributionMetric metric) {
            buf = java.nio.ByteBuffer.wrap(bytes).order(java.nio.ByteOrder.LITTLE_ENDIAN)
            this.metric = metric
        }

        static void decode(String b64, DistributionMetric metric) {
            if (!b64) return
            new SketchDecoder(java.util.Base64.getDecoder().decode(b64), metric).parse()
        }

        private void parse() {
            int limit = buf.limit()
            while (buf.position() < limit) {
                int tag = (int) readVarint()
                int field = tag >>> 3
                int wire  = tag & 7
                if (field == 1 && wire == 2) {          // IndexMapping
                    int len = (int) readVarint()
                    int end = buf.position() + len
                    parseIndexMapping(end)
                    buf.position(end)
                } else if (field == 4 && wire == 1) {   // zero_count
                    double zc = buf.getDouble()
                    metric.zeroCount = zc
                    metric.count += zc
                } else if ((field == 2 || field == 3) && wire == 2) {  // Store
                    int len = (int) readVarint()
                    int storeEnd = buf.position() + len
                    parseStore(storeEnd)
                    buf.position(storeEnd)
                } else {
                    skipField(wire)
                }
            }
        }

        private void parseIndexMapping(int end) {
            while (buf.position() < end) {
                int tag = (int) readVarint()
                int field = tag >>> 3
                int wire  = tag & 7
                if (field == 1 && wire == 1) {
                    metric.gamma = buf.getDouble()
                } else if (field == 2 && wire == 1) {
                    metric.indexOffset = buf.getDouble()
                } else {
                    skipField(wire)
                }
            }
        }

        private void parseStore(int end) {
            List<Double> contiguousCounts = null
            int contiguousOffset = 0

            while (buf.position() < end) {
                int tag = (int) readVarint()
                int field = tag >>> 3
                int wire  = tag & 7
                if (field == 1 && wire == 2) {          // bin_counts map entry
                    int len = (int) readVarint()
                    int entryEnd = buf.position() + len
                    parseBinCountEntry(entryEnd)
                    buf.position(entryEnd)
                } else if (field == 2 && wire == 2) {   // contiguous_bin_counts (packed doubles)
                    int len = (int) readVarint()
                    int dataEnd = buf.position() + len
                    contiguousCounts = []
                    while (buf.position() < dataEnd) contiguousCounts << buf.getDouble()
                } else if (field == 3 && wire == 0) {   // contiguous_bin_index_offset (sint32 zigzag)
                    long raw = readVarint()
                    contiguousOffset = (int)((raw >>> 1) ^ -(raw & 1))
                } else {
                    skipField(wire)
                }
            }

            // offset is known only after the full store is parsed
            contiguousCounts?.eachWithIndex { double c, int i ->
                if (c != 0.0) {
                    metric.bins.merge(contiguousOffset + i, c, { a, b -> a + b })
                    metric.count += c
                }
            }
        }

        private void parseBinCountEntry(int end) {
            long rawKey = 0L
            double value = 0.0
            while (buf.position() < end) {
                int tag = (int) readVarint()
                int field = tag >>> 3
                int wire  = tag & 7
                if (field == 1 && wire == 0) {          // sint32 key (zigzag varint)
                    rawKey = readVarint()
                } else if (field == 2 && wire == 1) {   // double value (count)
                    value = buf.getDouble()
                } else {
                    skipField(wire)
                }
            }
            if (value != 0.0) {
                int binIdx = (int)((rawKey >>> 1) ^ -(rawKey & 1))
                metric.bins.merge(binIdx, value, { a, b -> a + b })
                metric.count += value
            }
        }

        private long readVarint() {
            long result = 0L
            int shift = 0
            while (true) {
                int b = buf.get() & 0xFF
                result |= ((long)(b & 0x7F)) << shift
                if ((b & 0x80) == 0) return result
                shift += 7
            }
        }

        private void skipField(int wire) {
            switch (wire) {
                case 0:
                    while ((buf.get() & 0x80) != 0) {}
                    break
                case 1:
                    buf.position(buf.position() + 8)
                    break
                case 2:
                    // NOTE: must read varint into a local first — see skipField comment in git history.
                    int len = (int) readVarint()
                    buf.position(buf.position() + len)
                    break
                case 5:
                    buf.position(buf.position() + 4)
                    break
                default:
                    throw new IllegalStateException("Unknown protobuf wire type: $wire")
            }
        }
    }

    @ToString
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

        @Override
        public String toString() {
            return new StringJoiner(", ", Endpoint.class.getSimpleName() + "[", "]")
                    .add("method='" + method + "'")
                    .add("operationName='" + operationName + "'")
                    .add("path='" + path + "'")
                    .add("resourceName='" + resourceName + "'")
                    .toString();
        }
    }

    @ToString
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

        Logs(Map m) {
            this(m.logs as List)
        }

        Logs(List m) {
            logs = m.collect { new Log(it as Map) }
        }
    }

    @Canonical
    @ToString
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

    static class WithExtendedHeartbeat {
        static names = ['app-extended-heartbeat']
        List<ConfigurationEntry> configuration
        List<DependencyEntry> dependencies
        List<IntegrationEntry> integrations

        WithExtendedHeartbeat(Map m) {
            configuration = m.configuration?.collect { new ConfigurationEntry(it) }
            dependencies  = m.dependencies?.collect { new DependencyEntry(it) }
            integrations  = m.integrations?.collect { new IntegrationEntry(it) }
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

    static <T> List<T> waitForTelemetryData(AppSecContainer container, int timeoutSec,
                                            @ClosureParams(value = FromString, options = 'java.util.List<T>')
                                                    Closure<Boolean> cl,
                                            Class<T> cls, String path = '/hello.php') {
        List<T> messages = []
        def deadline = System.currentTimeSeconds() + timeoutSec
        def lastHttpReq = System.currentTimeSeconds() - 6
        while (System.currentTimeSeconds() < deadline) {
            if (System.currentTimeSeconds() - lastHttpReq > 5) {
                lastHttpReq = System.currentTimeSeconds()
                // used to flush global (not request-bound) telemetry metrics
                def request = container.buildReq(path).GET().build()
                def trace = container.traceFromRequest(request, ofString()) { HttpResponse<String> resp ->
                    assert resp.body().size() > 0
                }
            }
            def telData = container.drainTelemetry(500)
            messages.addAll(
                    TelemetryHelpers.filterMessages(telData, cls))
            if (cl.call(messages)) {
                break
            }
        }
        messages
    }

    static List<AppEndpoints> waitForAppEndpoints(AppSecContainer container, int timeoutSec,
                                                  @ClosureParams(value = FromString,
                                                          options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.AppEndpoints>')
                                                          Closure<Boolean> cl,
                                                  String path = '/') {
        waitForTelemetryData(container, timeoutSec, cl, AppEndpoints, path)
    }

    static List<GenerateDistributions> waitForDistributions(AppSecContainer container, int timeoutSec,
                                                            @ClosureParams(value = FromString,
                                                                    options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.GenerateDistributions>')
                                                                    Closure<Boolean> cl) {
        waitForTelemetryData(container, timeoutSec, cl, GenerateDistributions)
    }

    static List<GenerateMetrics> waitForMetrics(AppSecContainer container, int timeoutSec,
                                                @ClosureParams(value = FromString,
                                                        options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.GenerateMetrics>')
                                                        Closure<Boolean> cl) {
        waitForTelemetryData(container, timeoutSec, cl, GenerateMetrics)
    }

    static List<WithIntegrations> waitForIntegrations(AppSecContainer container, int timeoutSec,
                                                      @ClosureParams(value = FromString,
                                                              options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.WithIntegrations>')
                                                              Closure<Boolean> cl) {
        waitForTelemetryData(container, timeoutSec, cl, WithIntegrations)
    }

    static List<WithExtendedHeartbeat> waitForExtendedHeartbeat(AppSecContainer container, int timeoutSec,
                                                                @ClosureParams(value = FromString,
                                                                        options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.WithExtendedHeartbeat>')
                                                                        Closure<Boolean> cl) {
        waitForTelemetryData(container, timeoutSec, cl, WithExtendedHeartbeat)
    }

    static List<Logs> waitForLogs(AppSecContainer container, int timeoutSec,
                                  @ClosureParams(value = FromString,
                                          options = 'java.util.List<com.datadog.appsec.php.TelemetryHelpers.Logs>')
                                          Closure<Boolean> cl) {
        waitForTelemetryData(container, timeoutSec, cl, Logs)
    }
}
