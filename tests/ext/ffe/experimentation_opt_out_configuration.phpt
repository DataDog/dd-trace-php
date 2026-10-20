--TEST--
FFE immediate opt-out uses SDK configuration and preserves explicit overrides
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_FEATURE_FLAGS_EXPERIMENTATION_OPT_OUT_MODE= IMMEDIATE 
--FILE--
<?php
$root = getenv('TEST_PHP_SRCDIR') ?: dirname(dirname(dirname(__DIR__)));
$srcRoot = rtrim((string) ini_get('datadog.trace.sources_path'), '/');
if ($srcRoot === '' || !is_dir($srcRoot . '/api')) {
    $srcRoot = $root . '/src';
}
if (!is_dir($srcRoot . '/api')) {
    // PECL installs sources apart from tests and puts php_dir on include_path.
    $installed = stream_resolve_include_path('datadog_trace/src/api/FeatureFlags/Client.php');
    if ($installed !== false) {
        $srcRoot = dirname(dirname(dirname($installed)));
    }
}
spl_autoload_register(function ($class) use ($srcRoot) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $path = $srcRoot . '/api/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
\DDTrace\Testing\ffe_load_config(file_get_contents($root . '/tests/FeatureFlags/ffe-system-test-data/ufc-config.json'));
$client = new \DDTrace\FeatureFlags\Client();
$disabled = new \DDTrace\FeatureFlags\Client(null, \DDTrace\FeatureFlags\ExperimentationOptOutMode::DISABLED);
$context = array('targetingKey' => '19', 'attributes' => array('country' => 'UK'));
$cases = array(
    'absent' => array(false, null),
    'valid' => array(true, '2026-10-19T12:00:00Z'),
    'future' => array(true, '2099-01-01T00:00:00.123456789+05:30'),
    'null' => array(true, null),
    'boolean' => array(true, true),
    'array' => array(true, array()),
    'empty' => array(true, ''),
    'malformed' => array(true, 'invalid'),
);
foreach ($cases as $name => $case) {
    $subject = $context;
    if ($case[0]) {
        $subject['attributes']['dd_experimentation_opt_out_at'] = $case[1];
    }
    $details = $client->getStringDetails('new-user-onboarding', 'fallback', $subject);
    echo $name . '=' . $details->getValue() . ',' . ($details->getErrorCode() ?: 'OK') . "\n";
    echo $name . '_disabled=' . $disabled->getStringValue('new-user-onboarding', 'fallback', $subject) . "\n";
}
$context['attributes']['dd_experimentation_opt_out_at'] = '2026-10-19T00:00:00Z';
ini_set('datadog.feature_flags_experimentation_opt_out_mode', 'disabled');
echo 'snapshot=' . $client->getStringValue('new-user-onboarding', 'fallback', $context) . "\n";
echo 'new_client=' . (new \DDTrace\FeatureFlags\Client())->getStringValue('new-user-onboarding', 'fallback', $context) . "\n";
ini_set('datadog.feature_flags_experimentation_opt_out_mode', 'invalid');
try {
    new \DDTrace\FeatureFlags\Client();
    echo "invalid_mode=accepted\n";
} catch (\InvalidArgumentException $error) {
    echo "invalid_mode=rejected\n";
}
echo 'override=' . (new \DDTrace\FeatureFlags\Client(null, 'disabled'))->getStringValue('new-user-onboarding', 'fallback', $context) . "\n";
?>
--EXPECT--
absent=red,OK
absent_disabled=red
valid=fallback,OK
valid_disabled=red
future=fallback,OK
future_disabled=red
null=fallback,INVALID_CONTEXT
null_disabled=red
boolean=fallback,INVALID_CONTEXT
boolean_disabled=red
array=fallback,INVALID_CONTEXT
array_disabled=red
empty=fallback,INVALID_CONTEXT
empty_disabled=red
malformed=fallback,INVALID_CONTEXT
malformed_disabled=red
snapshot=fallback
new_client=red
invalid_mode=rejected
override=red
