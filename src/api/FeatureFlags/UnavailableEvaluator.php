<?php

namespace DDTrace\FeatureFlags;

final class UnavailableEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation is not fully enabled yet. '
        . 'Returning default values until the ddtrace FFE runtime path is available.';

    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array())
    {
        return new EvaluationDetails(
            $defaultValue,
            $expectedType,
            EvaluationReason::ERROR,
            null,
            EvaluationErrorCode::PROVIDER_NOT_READY,
            self::WARNING_MESSAGE,
            array(),
            array(),
            array(
                'ready' => false,
                'productionRuntime' => false,
                'reason' => 'runtime_unavailable',
            )
        );
    }
}
