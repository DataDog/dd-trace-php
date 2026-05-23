<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\Internal\Exposure\ExposureHook;

/**
 * @internal Datadog-owned bridge adapters only.
 *
 * Returns the composite of internal hooks installed for production callers of
 * `DDTrace\FeatureFlags\Client::create()`.
 *
 * This file is the merge-conflict point between the EVP exposures PR (#3910)
 * and the OTLP metrics PR (#3911) — each PR contributes one hook to the
 * composite, and the second-merge resolves the conflict by combining both.
 * Today (PR #3910 scope): the composite contains only `ExposureHook`.
 */
final class DefaultEvaluationCompletedHook
{
    private function __construct()
    {
    }

    public static function create()
    {
        return new CompositeEvaluationCompletedHook(array(
            ExposureHook::createDefault(),
        ));
    }
}
