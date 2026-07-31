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

$otlpHeaders = [
    'OTEL_EXPORTER_OTLP_HEADERS',
    'OTEL_EXPORTER_OTLP_METRICS_HEADERS',
    'OTEL_EXPORTER_OTLP_LOGS_HEADERS',
    'OTEL_EXPORTER_OTLP_TRACES_HEADERS',
];
foreach ($otlpHeaders as $h) {
    dd_trace_internal_fn('track_otel_config', $h, 'dd-api-key=SENTINEL_OTLP');
}
dd_trace_internal_fn('track_otel_config', 'DD_API_KEY', 'SENTINEL_INTERNAL_DD_API_KEY');
dd_trace_internal_fn('track_otel_config', 'OTEL_EXPORTER_OTLP_ENDPOINT', 'http://collector:4318');

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

$sentinels = [
    'SENTINEL_DD_API_KEY',
    'SENTINEL_INTERNAL_DD_API_KEY',
    'SENTINEL_OTLP',
];
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
        if (!$l || $l[0] != '{') {
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
Included
sentinel values in telemetry: array(0) {
}
DD_API_KEY reported: bool(false)
DD_TRACE_ENABLED reported: bool(false)
OTEL_EXPORTER_OTLP_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_METRICS_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_LOGS_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_TRACES_HEADERS reported: bool(false)
OTEL_EXPORTER_OTLP_ENDPOINT reported: bool(true)
DD_VERSION reported: bool(true)
--CLEAN--
<?php

@unlink(__DIR__ . '/sensitive-config-telemetry.out');
