<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\API\Trace;

use DDTrace\OpenTelemetry\SDK\Trace\Span;
use DDTrace\Propagator;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\API\Trace\TraceStateInterface;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\trace_id;

final class SpanContext implements SpanContextInterface
{
    /** @var Span */
    private $span;

    private bool $sampled;

    private bool $remote;

    private ?string $traceId;

    private ?string $spanId;

    private ?TraceStateInterface $traceState;

    private function __construct(Span $span, bool $sampled, bool $remote, ?TraceStateInterface $traceState)
    {
        $this->span = $span;
        $this->sampled = $sampled;
        $this->remote = $remote;
        $this->traceState = $traceState;
        $this->traceId = null;
        $this->spanId = null;
    }

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    private static function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getTraceId(): string
    {
        if ($this->traceId === null) {
            $this->traceId = str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT);
        }

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
        if ($this->spanId === null) {
            $this->spanId = str_pad(strtolower(self::largeBaseConvert(dd_trace_peek_span_id(), 10, 16)), 16, '0', STR_PAD_LEFT);
        }

        return $this->spanId;
    }

    public function getSpanIdBinary(): string
    {
        return hex2bin($this->getSpanId());
    }

    public function getTraceState(): ?TraceStateInterface
    {
        return $this->traceState;
        $headers = generate_distributed_tracing_headers();

        return isset($headers["tracestate"]) ? new TraceState($headers["tracestate"]) : null; // TODO: Check if the parsing is correct
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function isValid(): bool
    {
        return true; // TODO: Check what it means
    }

    public function isRemote(): bool
    {
        return $this->remote;
    }

    public function getTraceFlags(): int
    {
        return $this->sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
    }

    public static function createFromRemoteParent(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        // TODO: Implement createFromRemoteParent() method.
    }

    public static function create(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        // TODO: Implement create() method.
    }

    public static function getInvalid(): SpanContextInterface
    {
        // TODO: Implement getInvalid() method.
    }
}
