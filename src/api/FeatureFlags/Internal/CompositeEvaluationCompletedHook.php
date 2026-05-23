<?php

namespace DDTrace\FeatureFlags\Internal;

final class CompositeEvaluationCompletedHook implements EvaluationCompletedHook
{
    /** @var array<int, EvaluationCompletedHook> */
    private $hooks;

    public function __construct(array $hooks)
    {
        foreach ($hooks as $hook) {
            if (!$hook instanceof EvaluationCompletedHook) {
                throw new \InvalidArgumentException('Expected EvaluationCompletedHook instances');
            }
        }
        $this->hooks = array_values($hooks);
    }

    public function evaluationCompleted(EvaluationCompleted $evaluation)
    {
        foreach ($this->hooks as $hook) {
            try {
                $hook->evaluationCompleted($evaluation);
            } catch (\Throwable $throwable) {
                // Internal exposure/metric hooks must never affect flag evaluation results.
            }
        }
    }
}
