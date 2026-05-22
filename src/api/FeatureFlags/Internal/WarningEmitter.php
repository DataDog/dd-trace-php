<?php

namespace DDTrace\FeatureFlags\Internal;

interface WarningEmitter
{
    /**
     * @param string $message
     * @return void
     */
    public function warning($message);
}
