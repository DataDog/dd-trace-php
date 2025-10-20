<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Metrics;

use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;

/**
 * No-op MeterProvider implementation
 * 
 * Used as a fallback when no MeterProvider is configured
 */
final class NoopMeterProvider implements MeterProviderInterface
{
    /**
     * Get a no-op Meter
     * 
     * @param string $name
     * @param string|null $version
     * @param string|null $schemaUrl
     * @param AttributesInterface|null $attributes
     * @return MeterInterface
     */
    public function getMeter(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        ?AttributesInterface $attributes = null
    ): MeterInterface {
        return new NoopMeter();
    }
}

