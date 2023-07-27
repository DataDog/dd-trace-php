<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace as API;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Trace\TracerSharedState;

class Tracer implements API\TracerInterface
{

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;
    public function __construct(
        InstrumentationScopeInterface $instrumentationScope
    ) {
        $this->instrumentationScope = $instrumentationScope;
    }

    /**
     * @inheritDoc
     */
    public function spanBuilder(string $spanName): API\SpanBuilderInterface
    {

    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }
}