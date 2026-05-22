<?php

namespace DDTrace\FeatureFlags;

final class NoopMetricsRecorder implements MetricsRecorder
{
    public function recordEvaluation($flagKey, $valueType, $reason, $errorCode = null)
    {
    }
}
