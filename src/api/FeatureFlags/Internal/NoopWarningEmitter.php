<?php

declare(strict_types=1);

namespace DDTrace\FeatureFlags\Internal;

final class NoopWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
    }
}
