--TEST--
FFE span enrichment: evaluations aggregate onto the active root span's meta
--INI--
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

// Three evaluations under the same root span feed the SAME accumulator (keyed on
// the root via ObjectKVStore), so the tags aggregate rather than overwrite:
//  - flag.a: split, serial 100, logged subject "user-1"
//  - flag.b: split, serial 108, NOT logged (serial recorded, no subject)
//  - flag.c: runtime default (no serial, no variant)
$a = new EvaluationDetails('on', EvaluationType::STRING, EvaluationReason::SPLIT, 'treatment', null, null, array(), array('serialId' => 100, 'doLog' => true));
SpanEnrichmentRegistry::record('flag.a', $a, 'user-1');

$b = new EvaluationDetails('blue', EvaluationType::STRING, EvaluationReason::SPLIT, 'blue', null, null, array(), array('serialId' => 108, 'doLog' => false));
SpanEnrichmentRegistry::record('flag.b', $b, 'user-1');

$c = new EvaluationDetails('fallback', EvaluationType::STRING, EvaluationReason::DEFAULT_REASON, null, null, null, array(), array());
SpanEnrichmentRegistry::record('flag.c', $c, null);

$meta = \DDTrace\root_span()->meta;
$codec = new SpanEnrichmentAccumulator();

show('flags', $codec->decodeDeltaVarint($meta['ffe_flags_enc']));

$subjects = json_decode($meta['ffe_subjects_enc'], true);
$subjectKeys = array_keys($subjects);
$subjectVals = array_values($subjects);
show('subject_count', count($subjects));
show('subject_key_is_sha256', (bool) preg_match('/^[0-9a-f]{64}$/', (string) $subjectKeys[0]));
show('subject_ids', $codec->decodeDeltaVarint($subjectVals[0]));

show('defaults', json_decode($meta['ffe_runtime_defaults'], true));
?>
--EXPECT--
flags=[100,108]
subject_count=1
subject_key_is_sha256=true
subject_ids=[100]
defaults={"flag.c":"fallback"}
