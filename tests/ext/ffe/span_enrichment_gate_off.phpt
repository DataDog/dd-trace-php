--TEST--
FFE span enrichment: gate off writes no ffe_* tags onto the root span
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die('skip: the src/ PHP API is not shipped in the PECL test package');
}
?>
--INI--
datadog.experimental_flagging_provider_span_enrichment_enabled=0
--FILE--
<?php

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationReason;
use DDTrace\FeatureFlags\EvaluationType;
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

// With the gate off, record() is a no-op even for a normal split evaluation.
$a = new EvaluationDetails('on', EvaluationType::STRING, EvaluationReason::SPLIT, 'treatment', null, null, array(), array('serialId' => 100, 'doLog' => true));
SpanEnrichmentRegistry::record('flag.a', $a, 'user-1');

$meta = \DDTrace\root_span()->meta;
show('has_flags', isset($meta['ffe_flags_enc']));
show('has_subjects', isset($meta['ffe_subjects_enc']));
show('has_defaults', isset($meta['ffe_runtime_defaults']));
?>
--EXPECT--
has_flags=false
has_subjects=false
has_defaults=false
