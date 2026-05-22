<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\FeatureFlags\WarningEmitter;

final class NoopWarningEmitter implements WarningEmitter
{
    public function warning($message)
    {
    }
}
