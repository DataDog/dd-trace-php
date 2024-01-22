<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\API\Trace;

use DDTrace\OpenTelemetry\SDK\Trace\Span;
use DDTrace\RootSpanData;
use DDTrace\SpanData;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\API\Trace\TraceStateInterface;
use function DDTrace\generate_distributed_tracing_headers;

final class SpanContext implements SpanContextInterface
{
    /** @var SpanData */
    private $span;

    private bool $sampled;

    private bool $remote;

    private ?string $spanId;

    private static function getRootSpan(SpanData $span): RootSpanData
    {
        while (!($span instanceof RootSpanData) && $span->parent) {
            $span = $span->parent;
        }
        return $span;
    }

    private function __construct(SpanData $span, bool $sampled, bool $remote, ?string $spanId = null)
    {
        $this->span = $span;
        $this->sampled = $sampled;
        $this->remote = $remote;
        $this->spanId = $spanId ?: $this->span->hexId();
    }

    /**
     * @inheritDoc
     */
    public function getTraceId(): string
    {
        $rootSpan = self::getRootSpan($this->span);
        return $rootSpan->traceId;
    }

    public function getTraceIdBinary(): string
    {
        return hex2bin($this->getTraceId());
    }

    /**
     * @inheritDoc
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getSpanIdBinary(): string
    {
        return hex2bin($this->getSpanId());
    }

    public function getTraceState(): ?TraceStateInterface
    {
        $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        return new TraceState($traceContext['tracestate'] ?? null);
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function isValid(): bool
    {
        return SpanContextValidator::isValidTraceId(self::getRootSpan($this->span)->traceId) && SpanContextValidator::isValidSpanId($this->spanId);
    }

    public function isRemote(): bool
    {
        return $this->remote;
    }

    public function getTraceFlags(): int
    {
        return $this->sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
    }

    /** @inheritDoc */
    public static function createFromRemoteParent(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        return API\SpanContext::createFromRemoteParent($traceId, $spanId, $traceFlags, $traceState);
    }

    /** @inheritDoc */
    public static function create(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        return API\SpanContext::create($traceId, $spanId, $traceFlags, $traceState);
    }

    /** @inheritDoc */
    public static function getInvalid(): SpanContextInterface
    {
        return API\SpanContext::getInvalid();
    }

    public static function createFromLocalSpan(SpanData $span, bool $sampled, ?string $spanId = null)
    {
        return new self(
            $span,
            $sampled,
            false,
            $spanId
        );
    }
}
