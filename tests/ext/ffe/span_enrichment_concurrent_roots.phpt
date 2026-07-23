--TEST--
FFE span enrichment: concurrently-open root spans each keep their own tags
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die('skip: the src/ PHP API is not shipped in the PECL test package');
}
?>
--INI--
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_span_enrichment_enabled=1
--FILE--
<?php

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;

$root = getenv('TEST_PHP_SRCDIR');
if (!is_string($root) || $root === '') {
    $root = dirname(dirname(dirname(__DIR__)));
}
require_once $root . '/src/DDTrace/Util/ObjectKVStore.php';
foreach (array(
    'EvaluationType',
    'EvaluationReason',
    'EvaluationErrorCode',
    'EvaluationDetails',
    'SpanEnrichmentAccumulator',
    'SpanEnrichmentRegistry',
) as $classFile) {
    require_once $root . '/src/api/FeatureFlags/' . $classFile . '.php';
}

function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

$codec = new SpanEnrichmentAccumulator();

// Trace A: start_trace_span() creates a NEW root span on its own stack.
$rootA = \DDTrace\start_trace_span();
$a = new EvaluationDetails('on', EvaluationType::STRING, EvaluationReason::SPLIT, 'a', null, null, array(), array('serialId' => 100, 'doLog' => false));
SpanEnrichmentRegistry::record('flag.a', $a, null);

// Trace B: a second, concurrently-open root on its own stack (A stays open
// underneath). Models the fibers / multiple-span-stacks case.
$rootB = \DDTrace\start_trace_span();
$b = new EvaluationDetails('off', EvaluationType::STRING, EvaluationReason::SPLIT, 'b', null, null, array(), array('serialId' => 200, 'doLog' => false));
SpanEnrichmentRegistry::record('flag.b', $b, null);

// Each root carries only its own evaluation; keying the accumulator on the span
// object makes this correct without any manual root tracking.
show('rootA_flags', $codec->decodeDeltaVarint($rootA->meta['ffe_flags_enc']));
show('rootB_flags', $codec->decodeDeltaVarint($rootB->meta['ffe_flags_enc']));
show('rootA_has_b', in_array(200, $codec->decodeDeltaVarint($rootA->meta['ffe_flags_enc']), true));
show('rootB_has_a', in_array(100, $codec->decodeDeltaVarint($rootB->meta['ffe_flags_enc']), true));
?>
--EXPECT--
rootA_flags=[100]
rootB_flags=[200]
rootA_has_b=false
rootB_has_a=false
