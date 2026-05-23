<?php

namespace DDTrace\FeatureFlags\Internal;

final class CompositeEvaluationCompletedHook implements EvaluationCompletedHook
{
    private $hooks;

    public function __construct(array $hooks)
    {
        foreach ($hooks as $hook) {
            if (!$hook instanceof EvaluationCompletedHook) {
                throw new \InvalidArgumentException('Expected EvaluationCompletedHook instances');
            }
        }

        $this->hooks = $hooks;
    }

    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->evaluationCompleted($evaluation);
            } catch (\Throwable $throwable) {
                // One internal hook must not prevent later hooks from observing the evaluation.
            }
        }
    }
}
