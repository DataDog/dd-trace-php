<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace as API;

class Tracer implements API\TracerInterface
{

    /**
     * @inheritDoc
     */
    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        // TODO: Implement spanBuilder() method.
    }
}