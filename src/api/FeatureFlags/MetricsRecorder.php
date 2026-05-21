<?php

namespace DDTrace\FeatureFlags;

interface MetricsRecorder
{
    /**
     * @param string|null $errorCode
     * @return void
     */
    public function recordEvaluation($flagKey, $valueType, $reason, $errorCode = null);
}
