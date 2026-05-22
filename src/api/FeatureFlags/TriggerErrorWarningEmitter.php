<?php

namespace DDTrace\FeatureFlags;

final class TriggerErrorWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
        trigger_error($message, E_USER_WARNING);
    }
}
