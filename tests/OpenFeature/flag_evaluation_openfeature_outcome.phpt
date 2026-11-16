--TEST--
FFE OpenFeature observations match terminal hook outcomes and preserve evaluation-time metadata
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: OpenFeature requires PHP 8+');
if (!is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
?>
--ENV--
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:9
--FILE--
<?php
require __DIR__ . '/../Common/FeatureFlags/flag_evaluation_openfeature.inc';
foreach (array('success', 'before-error', 'after-error', 'provider-error', 'error-hook-error', 'finally-error') as $case) {
    \DDTrace\Testing\ffe_load_config(ffeOutcomeConfig());
    $ffeRecords = array();
    $hook = new FfeOutcomeHook();
    $afterStarted = null;
    if ($case === 'before-error' || $case === 'error-hook-error') {
        $hook->onBefore = function () { throw new RuntimeException('before failed'); };
    }
    if ($case === 'after-error') {
        $hook->onAfter = function () use (&$afterStarted) {
            $afterStarted = (int) (microtime(true) * 1000);
            \DDTrace\Testing\ffe_load_config(ffeOutcomeConfig(false));
            usleep(20000);
            throw new RuntimeException('after failed');
        };
    }
    if ($case === 'error-hook-error') $hook->onError = function () { throw new RuntimeException('error hook failed'); };
    if ($case === 'finally-error') $hook->onFinally = function () { throw new RuntimeException('finally failed'); };
    $client = ffeOutcomeClient(new \DDTrace\OpenFeature\DataDogProvider(), $hook);
    $details = $client->getStringDetails($case === 'provider-error' ? 'missing' : 'flag', 'fallback');
    $record = count($ffeRecords) === 1 ? $ffeRecords[0] : null;
    $error = $details->getError() ? $details->getError()->getResolutionErrorCode()->getValue() : null;
    echo $case, ': ', json_encode(array('value' => $details->getValue(), 'error' => $error,
        'records' => count($ffeRecords), 'variant' => $record ? $record[1] : null,
        'recordedError' => $record ? $record[5] : null, 'default' => $record ? $record[6] : null,
        'consent' => $record ? $record[7] : null)), "\n";
    if ($case === 'after-error') {
        echo 'timestamp_preserved='; var_dump($record !== null && $record[8] <= $afterStarted);
        echo 'allocation_cleared='; var_dump($record !== null && $record[2] === null);
    }
}
\DDTrace\Testing\ffe_load_config(ffeOutcomeConfig());
$ffeRecords = array();
$provider = new \DDTrace\OpenFeature\DataDogProvider();
$native = new \DDTrace\FeatureFlags\Client();
echo 'direct_provider=', $provider->resolveStringValue('flag', 'fallback')->getValue(), "\n";
echo 'native=', $native->getStringValue('flag', 'fallback'), "\n";
echo 'direct_and_native_records='; var_dump(count($ffeRecords));
?>
--EXPECT--
success: {"value":"blue","error":null,"records":1,"variant":"blue","recordedError":null,"default":false,"consent":true}
before-error: {"value":"fallback","error":"GENERAL","records":1,"variant":null,"recordedError":"GENERAL","default":true,"consent":false}
after-error: {"value":"fallback","error":"GENERAL","records":1,"variant":null,"recordedError":"GENERAL","default":true,"consent":true}
timestamp_preserved=bool(true)
allocation_cleared=bool(true)
provider-error: {"value":"fallback","error":"FLAG_NOT_FOUND","records":1,"variant":null,"recordedError":"FLAG_NOT_FOUND","default":true,"consent":true}
error-hook-error: {"value":"fallback","error":"GENERAL","records":1,"variant":null,"recordedError":"GENERAL","default":true,"consent":false}
finally-error: {"value":"blue","error":null,"records":1,"variant":"blue","recordedError":null,"default":false,"consent":true}
direct_provider=blue
native=blue
direct_and_native_records=int(2)
