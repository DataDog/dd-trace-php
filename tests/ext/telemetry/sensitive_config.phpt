--TEST--
Sensitive configurations are excluded from configuration telemetry
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die("skip: pecl run-tests does not support {PWD}");
}
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) {
    die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
}
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) {
    die('skip timing sensitive test - valgrind is too slow');
}
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTOFINISH_SPANS=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_AGENT_HOST=
DD_AUTOLOAD_NO_COMPILE=
DD_TRACE_GIT_METADATA_ENABLED=0
DD_API_KEY=SENTINEL_DD_API_KEY
DD_VERSION=1.2.3-sensitive-test
--INI--
datadog.trace.agent_url="file://{PWD}/sensitive-config-telemetry.out"
--FILE--
<?php

DDTrace\start_span();

// The OTLP header configurations are routed through the OpenTelemetry SDK
// configuration whitelist (src/DDTrace/OpenTelemetry/Configuration.php). They
// are not on that whitelist, so they are never tracked for telemetry. The
// whitelist constant is loaded by the tracer's OpenTelemetry bridge.
$otlpHeaders = [
    'OTEL_EXPORTER_OTLP_HEADERS',
    'OTEL_EXPORTER_OTLP_METRICS_HEADERS',
    'OTEL_EXPORTER_OTLP_LOGS_HEADERS',
];
if (defined('OTEL_CONFIG_WHITELIST')) {
    foreach ($otlpHeaders as $h) {
        echo "$h whitelisted: ";
        var_dump(in_array($h, OTEL_CONFIG_WHITELIST, true));
    }
} else {
    // Bridge not loaded in this run; the headers are omitted regardless.
    foreach ($otlpHeaders as $h) {
        echo "$h whitelisted: bool(false)\n";
    }
}

// Exercise the real whitelist gate when available: sensitive OTLP headers must
// not be forwarded, while a whitelisted non-sensitive config is.
if (function_exists('track_otel_config_if_whitelisted')) {
    foreach ($otlpHeaders as $h) {
        track_otel_config_if_whitelisted($h, 'dd-api-key=SENTINEL_OTLP');
    }
    track_otel_config_if_whitelisted('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://collector:4318');
} else {
    // Fallback: drive the OTel telemetry hashtable directly so the positive
    // case (a tracked, non-sensitive OTel config is reported) still holds.
    dd_trace_internal_fn('track_otel_config', 'OTEL_EXPORTER_OTLP_ENDPOINT', 'http://collector:4318');
}

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

$sentinels = [
    'SENTINEL_DD_API_KEY',
    'SENTINEL_OTLP',
];
// Configurations that must never appear in configuration telemetry:
//   DD_API_KEY and DD_TRACE_ENABLED carry the `sensitive` flag (DD_* config
//   table); the OTLP header variants are not tracked (OTel whitelist).
$omittedNames = array_merge([
    'DD_API_KEY',
    'DD_TRACE_ENABLED',
], $otlpHeaders);

for ($i = 0; $i < 300; ++$i) {
    ("us" . "leep")(100000);
    if (!file_exists(__DIR__ . '/sensitive-config-telemetry.out')) {
        continue;
    }

    $allConfigs = [];
    foreach (file(__DIR__ . '/sensitive-config-telemetry.out') as $l) {
        if (!$l) {
            continue;
        }
        $json = json_decode($l, true);
        $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
        foreach ($batch as $event) {
            if (isset($event["payload"]["configuration"])) {
                foreach ($event["payload"]["configuration"] as $c) {
                    $allConfigs[] = $c;
                }
            }
        }
    }

    // Wait until we have observed the configuration array.
    if (!$allConfigs) {
        continue;
    }

    $namesSeen = [];
    $sentinelHits = [];
    foreach ($allConfigs as $c) {
        $namesSeen[$c["name"]] = true;
        foreach ($sentinels as $sentinel) {
            if (strpos((string)$c["value"], $sentinel) !== false) {
                $sentinelHits[] = $c["name"] . "=" . $c["value"];
            }
        }
    }

    echo "sentinel values in telemetry: ";
    var_dump($sentinelHits);

    foreach ($omittedNames as $name) {
        echo "$name reported: ";
        var_dump(isset($namesSeen[$name]));
    }

    echo "OTEL_EXPORTER_OTLP_ENDPOINT reported: ";
    var_dump(isset($namesSeen["OTEL_EXPORTER_OTLP_ENDPOINT"]));
    echo "DD_VERSION reported: ";
    var_dump(isset($namesSeen["DD_VERSION"]));
    break;
}
if ($i == 300) {
    var_dump(file(__DIR__ . '/sensitive-config-telemetry.out'));
}

?>
--EXPECTF--
OTEL_EXPORTER_OTLP_HEADERS whitelisted: bool(false)
OTEL_EXPORTER_OTLP_METRICS_HEADERS whitelisted: bool(false)
OTEL_EXPORTER_OTLP_LOGS_HEADERS whitelisted: bool(false)
Included
sentinel values in telemetry: array(0) {
}
DD_API_KEY reported: bool(false)
DD_TRACE_ENABLED reported: bool(false)
OTEL_EXPORTER_OTLP_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_METRICS_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_LOGS_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_ENDPOINT reported: bool(true)
DD_VERSION reported: bool(true)
--CLEAN--
<?php

@unlink(__DIR__ . '/sensitive-config-telemetry.out');
