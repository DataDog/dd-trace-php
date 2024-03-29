<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\API\Trace;

use DDTrace\OpenTelemetry\SDK\Trace\Span;
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

    private ?string $traceId;

    private ?string $spanId;

    private bool $isValid = true;

    private ?string $currentTracestateString = null;

    private ?TraceStateInterface $currentTracestateInstance = null;

    private function __construct(SpanData $span, bool $sampled, bool $remote, ?string $traceId = null, ?string $spanId = null)
    {
        $this->span = $span;
        $this->sampled = $sampled;
        $this->remote = $remote;
        $this->traceId = $traceId ?: \DDTrace\root_span()->traceId;
        $this->spanId = $spanId ?: $this->span->hexId();

        // TraceId must be exactly 16 bytes (32 chars) and at least one non-zero byte
        // SpanId must be exactly 8 bytes (16 chars) and at least one non-zero byte
        if (!SpanContextValidator::isValidTraceId($this->traceId) || !SpanContextValidator::isValidSpanId($this->spanId)) {
            $this->traceId = SpanContextValidator::INVALID_TRACE;
            $this->spanId = SpanContextValidator::INVALID_SPAN;
            $this->isValid = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getTraceId(): string
    {
        return $this->traceId;
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
        $current = \DDTrace\active_stack();
        if ($current !== $this->span->stack) {
            \DDTrace\switch_stack($this->span);
            $traceContext = generate_distributed_tracing_headers(['tracecontext']);
            \DDTrace\switch_stack($current);
        } else {
            $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        }
        $newTracestate = $traceContext['tracestate'] ?? null;

        if ($this->currentTracestateInstance === null || $this->currentTracestateString !== $newTracestate) {
            $this->currentTracestateString = $newTracestate;
            $this->currentTracestateInstance = new TraceState($newTracestate);
        }

        return $this->currentTracestateInstance;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function isValid(): bool
    {
        return $this->isValid;
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

    public static function createFromLocalSpan(SpanData $span, bool $sampled, ?string $traceId = null, ?string $spanId = null)
    {
        return new self(
            $span,
            $sampled,
            false,
            $traceId,
            $spanId
        );
    }
}
