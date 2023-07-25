<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final class TracerProvider implements TracerProviderInterface
{

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        // TODO: Implement forceFlush() method.
    }

    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        // TODO: Implement getTracer() method.
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        // TODO: Implement shutdown() method.
    }
}