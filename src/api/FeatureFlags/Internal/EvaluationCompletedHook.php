<?php

namespace DDTrace\FeatureFlags\Internal;

interface EvaluationCompletedHook
{
    /**
     * @return void
     */
    public function evaluationCompleted(EvaluationCompleted $evaluation);
}
