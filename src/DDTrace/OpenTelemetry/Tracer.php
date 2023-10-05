<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace as API;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Trace\TracerSharedState;

class Tracer implements API\TracerInterface
{
    public const FALLBACK_SPAN_NAME = 'empty';

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
        if (ctype_space($spanName)) {
            $spanName = self::FALLBACK_SPAN_NAME;
        }

        return new SpanBuilder(
            $spanName,
            $this->instrumentationScope
        );
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }
}
