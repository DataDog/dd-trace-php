--TEST--
FFE span enrichment: concurrently-open root spans each keep their own tags
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

// Root A on the initial stack.
\DDTrace\start_span();
$rootA = \DDTrace\root_span();
$a = new EvaluationDetails('on', EvaluationType::STRING, EvaluationReason::SPLIT, 'a', null, null, array(), array('serialId' => 100, 'doLog' => false));
SpanEnrichmentRegistry::record('flag.a', $a, null);

// Root B on a second, independent stack (models concurrent roots / fibers).
\DDTrace\create_stack();
\DDTrace\start_span();
$rootB = \DDTrace\root_span();
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
