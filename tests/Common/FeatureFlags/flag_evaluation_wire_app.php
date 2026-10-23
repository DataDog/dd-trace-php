<?php

require __DIR__ . '/ffe_api_bootstrap.inc';
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', null, function ($hook) {
    echo 'submission=', json_encode($hook->returned), "\n";
});

$config = array(
    'createdAt' => '2026-05-22T00:00:00Z',
    'environment' => array('name' => 'test'),
    'observeFullEvaluationData' => true,
    'flags' => array(),
);
foreach (array('consented', 'protected', 'coerced', 'empty', 'missing', 'malformed', 'hook-after-error') as $key) {
    $config['flags'][$key] = array(
        'key' => $key, 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => in_array($key, array('consented', 'protected'), true),
            'splits' => array(array('variationKey' => 'blue', 'serialId' => 0, 'shards' => array())),
        )),
    );
}
\DDTrace\Testing\ffe_load_config(json_encode($config));
$client = new \DDTrace\FeatureFlags\Client();
$context = array('targetingKey' => 'subject', 'attributes' => array('plan' => 'pro'));
if (getenv('PHP_FFE_WIRE_OPENFEATURE') === '1') {
    require __DIR__ . '/flag_evaluation_openfeature.inc';
    $api = \OpenFeature\OpenFeatureAPI::getInstance();
    $api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
    $client = $api->getClient('php-ffe-wire');
    $context = new \OpenFeature\implementation\flags\EvaluationContext('subject',
        new \OpenFeature\implementation\flags\Attributes(array('plan' => 'pro')));
}
for ($i = 0; $i < 2; ++$i) {
    if ($client->getStringValue('consented', 'fallback', $context) !== 'blue') {
        throw new RuntimeException('assignment failed');
    }
}
$config['observeFullEvaluationData'] = false;
\DDTrace\Testing\ffe_load_config(json_encode($config));
$protectedKey = 'ffe-protected-pii-canary@example.test';
$protectedAttributes = array('email' => 'ffe-context-pii-canary@example.test');
$context = array('targetingKey' => $protectedKey, 'attributes' => $protectedAttributes);
if (getenv('PHP_FFE_WIRE_OPENFEATURE') === '1') {
    $context = new \OpenFeature\implementation\flags\EvaluationContext($protectedKey,
        new \OpenFeature\implementation\flags\Attributes($protectedAttributes));
}
if ($client->getStringValue('protected', 'fallback', $context) !== 'blue') {
    throw new RuntimeException('assignment failed');
}
if (getenv('PHP_FFE_WIRE_OPENFEATURE') === '1') {
    $hook = new FfeOutcomeHook();
    $hook->onBefore = function ($context) {
        if ($context->getFlagKey() === 'hook-before-error') throw new RuntimeException('ffe-error-pii-canary@example.test');
        return null;
    };
    $hook->onAfter = function ($context) {
        if ($context->getFlagKey() === 'hook-after-error') throw new RuntimeException('ffe-error-pii-canary@example.test');
    };
    $client->addHooks($hook);
    foreach (array('hook-before-error', 'hook-after-error') as $flag) {
        if ($client->getStringValue($flag, 'fallback', $context) !== 'fallback') {
            throw new RuntimeException('hook failure did not return default');
        }
    }
}
echo 'exposure_flush=', json_encode(\DDTrace\Testing\flush_ffe_exposures()), "\n";
echo "evaluated\n";
$nativeClient = new \DDTrace\FeatureFlags\Client();
foreach (array('coerced' => 123, 'empty' => '', 'missing' => null, 'malformed' => "bad-\xff") as $flag => $key) {
    if ($nativeClient->getStringValue($flag, 'fallback', array('targetingKey' => $key)) !== 'blue') {
        throw new RuntimeException('identity handling changed assignment');
    }
}
// Fixed timestamps prove that submission preserves the evaluation time rather
// than reading the clock again when the C bridge or sidecar handles the event.
foreach (array(1700000000123, 1700000000456) as $timestamp) {
    \DDTrace\Internal\record_ffe_flag_evaluation(
        'timestamped', 'blue', 'allocation', null, array(), null, false, false, $timestamp
    );
}
// Exercise the PHP/native snapshot boundary directly: the public evaluator
// already filters non-scalars before this boundary, but internal calls must
// still omit unsafe fields without losing the observation or invoking objects.
class FfeUnserializableContext {
    public function __toString() { throw new RuntimeException('must not convert context'); }
}
$bounded = array('rejected' => str_repeat('x', 257));
for ($i = 1; $i < 256; ++$i) $bounded['field-' . $i] = $i;
$bounded['beyond-limit'] = 'must-not-be-inspected';
$unicode = array(
    str_repeat('é', 256) => str_repeat('😀', 256),
    str_repeat('é', 257) => 'overlong-key',
    'overlong-value' => str_repeat('😀', 257),
    'invalid-value' => "bad-\xff", "bad-\xff" => 'invalid-key',
    'nan' => NAN, 'infinity' => INF,
    'object' => new FfeUnserializableContext(), 'nested' => array('ignored'),
    'bool' => false, 'integer' => -42, 'float' => 1.5,
    0 => 'zero-key', -1 => 'negative-key', 'empty' => '',
);
$original = 'before-submission';
$snapshot = array('value' => &$original);
foreach (array('bounded' => $bounded, 'unicode' => $unicode, 'snapshot' => $snapshot,
    'all-invalid' => array('bad' => NAN, 'object' => new FfeUnserializableContext())) as $flag => $attributes) {
    if (!\DDTrace\Internal\record_ffe_flag_evaluation(
        $flag, 'blue', 'allocation', 'subject', $attributes, null, false, true, 1700000000123
    )) throw new RuntimeException('context observation rejected');
}
$original = 'after-submission';
\DDTrace\Internal\record_ffe_flag_evaluation(
    'consented-canary', 'blue', null, 'ffe-consented-pii-canary@example.test',
    array('email' => 'ffe-consented-context-canary@example.test'),
    'ffe-error-pii-canary@example.test', false, true, 1700000000123
);
// Follow the existing broken-pipe telemetry test: a normal shared-sender
// operation notices the broken connection and replays its configuration.
dd_trace_internal_fn('break_sidecar_connection');
dd_trace_internal_fn('stats_sidecar');
if (!\DDTrace\Internal\record_ffe_flag_evaluation(
    'recovered', 'blue', null, $protectedKey, $protectedAttributes, null, false, false, 1700000000123
)) throw new RuntimeException('submission after shared-sender recovery rejected');
// Give the ordinary sidecar coalescer time to send while the parent captures.
// Shutdown delivery has its own lifecycle tests; this tests the live writer.
usleep(1000000);
