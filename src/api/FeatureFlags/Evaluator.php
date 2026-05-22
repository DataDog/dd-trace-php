<?php

namespace DDTrace\FeatureFlags;

interface Evaluator
{
    /**
     * @param string $flagKey
     * @param string $expectedType One of EvaluationType::*.
     * @param mixed $defaultValue
     * @param string|null $targetingKey
     * @param array<string, bool|int|float|string> $attributes
     * @return EvaluationDetails
     */
    public function evaluate($flagKey, $expectedType, $defaultValue, $targetingKey = null, array $attributes = array());
}
