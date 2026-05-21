<?php

namespace DDTrace\FeatureFlags;

interface WarningEmitter
{
    /**
     * @param string $message
     * @return void
     */
    public function warning($message);
}
