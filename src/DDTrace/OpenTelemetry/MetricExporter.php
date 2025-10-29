<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Otlp;

use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use RuntimeException;
use Throwable;

/**
 * Custom MetricExporter for Datadog that properly implements per-instrument temporality selection
 * according to Datadog implementation guidelines.
 *
 * This class replaces the vendor MetricExporter to fix the bug where it doesn't properly
 * call AggregationTemporalitySelectorInterface objects.
 */
final class MetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    private \OpenTelemetry\Contrib\Otlp\ProtobufSerializer $serializer;
    private string|Temporality|AggregationTemporalitySelectorInterface|null $temporalitySelector;

    /**
     * @psalm-param TransportInterface<SUPPORTED_CONTENT_TYPES> $transport
     */
    public function __construct(
        private readonly TransportInterface $transport,
        string|Temporality|AggregationTemporalitySelectorInterface|null $temporality = null,
    ) {
        if (!class_exists('\Google\Protobuf\Api')) {
            throw new RuntimeException('No protobuf implementation found (ext-protobuf or google/protobuf)');
        }
        $this->serializer = ProtobufSerializer::forTransport($this->transport);

        // If temporality is 'Delta' (the Datadog default), create a custom selector
        // that enforces UpDownCounters to always use CUMULATIVE
        if ($temporality === Temporality::DELTA || $temporality === 'Delta') {
            error_log("Creating Datadog-compliant temporality selector");
            $this->temporalitySelector = new class implements AggregationTemporalitySelectorInterface {
                public function temporality(MetricMetadataInterface $metric): Temporality|string|null {
                    $instrumentType = $metric->instrumentType();
                    error_log("Selector: instrument=" . $instrumentType . " name=" . $metric->name());

                    // UpDownCounter (synchronous and asynchronous) MUST always be CUMULATIVE
                    if ($instrumentType === InstrumentType::UP_DOWN_COUNTER ||
                        $instrumentType === InstrumentType::ASYNCHRONOUS_UP_DOWN_COUNTER) {
                        error_log("  -> CUMULATIVE (UpDownCounter)");
                        return Temporality::CUMULATIVE;
                    }

                    // All other instruments use DELTA
                    error_log("  -> DELTA");
                    return Temporality::DELTA;
                }
            };
        } else {
            $this->temporalitySelector = $temporality;
        }
    }

    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        // If we have a selector object, call its temporality method
        if ($this->temporalitySelector instanceof AggregationTemporalitySelectorInterface) {
            return $this->temporalitySelector->temporality($metric);
        }

        // Otherwise return the static value or fall back to metric's default
        return $this->temporalitySelector ?? $metric->temporality();
    }

    public function export(iterable $batch): bool
    {
        return $this->transport
            ->send($this->serializer->serialize((new \OpenTelemetry\Contrib\Otlp\MetricConverter($this->serializer))->convert($batch)))
            ->map(function (?string $payload): bool {
                if ($payload === null) {
                    return true;
                }

                $serviceResponse = new ExportMetricsServiceResponse();
                $this->serializer->hydrate($serviceResponse, $payload);

                return true;
            })
            ->catch(static function (Throwable $throwable): bool {
                error_log('MetricExporter: Export failure - ' . $throwable->getMessage());
                return false;
            })
            ->await();
    }

    public function shutdown(): bool
    {
        return $this->transport->shutdown();
    }

    public function forceFlush(): bool
    {
        return $this->transport->forceFlush();
    }
}
