<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\Internal\Metric\EvaluationMetricHook;

/**
 * @internal Datadog-owned bridge adapters only.
 *
 * Returns the composite of internal hooks installed for production callers of
 * `DDTrace\FeatureFlags\Client::create()`.
 *
 * This file is the merge-conflict point between the EVP exposures PR (#3910)
 * and this OTLP metrics PR (#3911). Each PR contributes one hook to the
 * composite. Today (PR #3911 scope): the composite contains only
 * `EvaluationMetricHook`. The second PR to merge resolves the conflict by
 * combining both `ExposureHook` and `EvaluationMetricHook` in the composite.
 *
 * The `createWithoutMetric()` factory is used by `DDTrace\OpenFeature\DataDogProvider`:
 * the OpenFeature path records `feature_flag.evaluations` via an
 * OpenFeature SDK `Hook` (`EvalMetricsHook`) rather than through the DD
 * Client hook, so the DD Client composite for the OpenFeature path
 * intentionally excludes the metric hook to avoid double-counting.
 */
final class DefaultEvaluationCompletedHook
{
    private function __construct()
    {
    }

    public static function create()
    {
        return new CompositeEvaluationCompletedHook(array(
            EvaluationMetricHook::createDefault(),
        ));
    }

    /**
     * @internal Datadog-owned bridge adapters only.
     *
     * Composite used by the PHP 8 OpenFeature `DataDogProvider`. Excludes
     * the metric hook because the OpenFeature SDK Hook layer
     * (`EvalMetricsHook`) records the metric for that path.
     */
    public static function createWithoutMetric()
    {
        return new CompositeEvaluationCompletedHook(array());
    }
}
