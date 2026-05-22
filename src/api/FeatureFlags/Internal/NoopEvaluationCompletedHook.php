<?php

namespace DDTrace\FeatureFlags\Internal;

final class NoopEvaluationCompletedHook implements EvaluationCompletedHook
{
    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
    }
}
