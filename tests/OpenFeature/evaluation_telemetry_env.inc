<?php

require __DIR__ . '/vendor/autoload.php';

use DDTrace\FeatureFlags\SpanEnrichmentAccumulator;
use DDTrace\FeatureFlags\SpanEnrichmentRegistry;
use DDTrace\OpenFeature\DataDogProvider;
use DDTrace\Util\ObjectKVStore;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\OpenFeatureAPI;

\DDTrace\Testing\ffe_load_config(json_encode([
    'createdAt' => '2026-01-01T00:00:00Z',
    'environment' => ['name' => 'test'],
    'flags' => ['flag' => [
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => ['treatment' => ['key' => 'treatment', 'value' => 'blue']],
        'allocations' => [[
            'key' => 'allocation', 'rules' => [], 'doLog' => true,
            'splits' => [['variationKey' => 'treatment', 'serialId' => 101, 'shards' => []]],
        ]],
    ]],
]));

function showEvaluation($label, DataDogProvider $provider, array $attributes): void
{
    $api = OpenFeatureAPI::getInstance();
    $api->setProvider($provider);
    $root = \DDTrace\root_span();
    ObjectKVStore::put($root, SpanEnrichmentRegistry::ACCUMULATOR_KEY, new SpanEnrichmentAccumulator());
    unset($root->meta['ffe_flags_enc'], $root->meta['ffe_subjects_enc']);
    $before = dd_trace_internal_fn('ffe_buffer_counts');
    $details = $api->getClient()->getStringDetails('flag', 'fallback', new EvaluationContext('subject', new Attributes($attributes)));
    $after = dd_trace_internal_fn('ffe_buffer_counts');
    echo $label, '=', json_encode([
        'value' => $details->getValue(),
        'variant' => $details->getVariant(),
        'reason' => $details->getReason(),
        'error' => $details->getError(),
        'metrics' => $after['evaluation_metrics'] - $before['evaluation_metrics'],
        'exposures' => $after['exposures'] - $before['exposures'],
        'annotation' => isset($root->meta['ffe_flags_enc']),
    ]), "\n";
}

$optedOut = ['dd_evaluation_telemetry_opt_out' => true];
$fromEnvironment = new DataDogProvider();
showEvaluation('omitted', $fromEnvironment, $optedOut);
showEvaluation('explicit-false', new DataDogProvider(enableEvaluationTelemetryOptOutParameter: false), $optedOut);
showEvaluation('explicit-true', new DataDogProvider(enableEvaluationTelemetryOptOutParameter: true), $optedOut);
showEvaluation('string-true', $fromEnvironment, ['dd_evaluation_telemetry_opt_out' => 'true']);
showEvaluation('missing', $fromEnvironment, []);

// A provider keeps its construction-time decision when tracer config changes.
$config = 'DD_FEATURE_FLAGS_EVALUATION_TELEMETRY_PER_SUBJECT_OPT_OUT_ENABLED';
ini_set('datadog.feature_flags_evaluation_telemetry_per_subject_opt_out_enabled', dd_trace_env_config($config) ? '0' : '1');
showEvaluation('existing-provider', $fromEnvironment, $optedOut);
showEvaluation('new-provider', new DataDogProvider(), $optedOut);
