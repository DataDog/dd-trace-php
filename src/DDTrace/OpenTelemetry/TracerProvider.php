<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactoryInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanLimits;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final class TracerProvider implements TracerProviderInterface
{
    /** @param list<SpanProcessorInterface>|SpanProcessorInterface|null $spanProcessors */
    public function __construct(
        $spanProcessors = [],
        SamplerInterface $sampler = null,
        ResourceInfo $resource = null,
        SpanLimits $spanLimits = null,
        IdGeneratorInterface $idGenerator = null,
        ?InstrumentationScopeFactoryInterface $instrumentationScopeFactory = null
    ) {
        // TODO: Span Processors, Sampler, and ID Generation are future works

        $this->instrumentationScopeFactory = $instrumentationScopeFactory ?? new InstrumentationScopeFactory(Attributes::factory());
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        // TODO: Span Processors are future works
    }

    public function getTracer(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = []
    ): API\TracerInterface {
        return new Tracer(
            $this->instrumentationScopeFactory->create($name, $version, $schemaUrl, $attributes)
        );
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        // TODO: Span processors are future works
    }

    public static function builder(): TracerProviderBuilder
    {
        return new TracerProviderBuilder();
    }
}
