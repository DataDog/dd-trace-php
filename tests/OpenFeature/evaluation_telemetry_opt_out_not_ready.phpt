--TEST--
OpenFeature evaluation telemetry opt-out preserves provider-not-ready defaults
--SKIPIF--
<?php
if (!function_exists('DDTrace\\Testing\\ffe_load_config')) die('skip native feature flag evaluator required');
if (!file_exists(__DIR__ . '/vendor/autoload.php')) die('skip OpenFeature SDK required');
?>
--INI--
datadog.metrics_otel_enabled=1
datadog.experimental_flagging_provider_span_enrichment_enabled=1
--FILE--
<?php
$loader = require __DIR__ . '/vendor/autoload.php';
// PHPUnit's PHPT subprocess does not inherit the native autoloader's sources path.
$loader->addPsr4('DDTrace\\', [__DIR__ . '/../../src/DDTrace', __DIR__ . '/../../src/api']);

use DDTrace\Log\NullLogger;
use DDTrace\OpenFeature\DataDogProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new DataDogProvider(new NullLogger('error'), true));
$client = $api->getClient();
var_dump(\DDTrace\ffe_has_config());
foreach ([true, false] as $optOut) {
    $details = $client->getStringDetails('flag', 'fallback', new EvaluationContext('subject', new Attributes([
        'dd_evaluation_telemetry_opt_out' => $optOut,
    ])));
    echo json_encode([
        'value' => $details->getValue(),
        'reason' => $details->getReason(),
        'error' => $details->getError()->getResolutionErrorCode()->getValue(),
        'counts' => dd_trace_internal_fn('ffe_buffer_counts'),
        'defaults' => isset(\DDTrace\root_span()->meta['ffe_runtime_defaults']),
    ]), "\n";
}
?>
--EXPECT--
bool(false)
{"value":"fallback","reason":"ERROR","error":"PROVIDER_NOT_READY","counts":{"evaluation_metrics":0,"exposures":0},"defaults":false}
{"value":"fallback","reason":"ERROR","error":"PROVIDER_NOT_READY","counts":{"evaluation_metrics":1,"exposures":0},"defaults":true}
