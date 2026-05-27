<?php

namespace DDTrace\FeatureFlags\Internal;

use DDTrace\FeatureFlags\EvaluationDetails;
use DDTrace\FeatureFlags\EvaluationErrorCode;
use DDTrace\FeatureFlags\EvaluationReason;

final class UnavailableEvaluator implements Evaluator
{
    const WARNING_MESSAGE = 'Datadog-backed PHP feature flag evaluation is unavailable for this client. '
        . 'Returning default values because the ddtrace FFE runtime path was not available when the client was created.';

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
