<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Throwable;

final class Span extends API\Span implements ReadWriteSpanInterface
{

    public function getName(): string
    {
        // TODO: Implement getName() method.
    }

    public function getParentContext(): API\SpanContextInterface
    {
        // TODO: Implement getParentContext() method.
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        // TODO: Implement getInstrumentationScope() method.
    }

    public function hasEnded(): bool
    {
        // TODO: Implement hasEnded() method.
    }

    /**
     * @inheritDoc
     */
    public function toSpanData(): SpanDataInterface
    {
        // TODO: Implement toSpanData() method.
    }

    /**
     * @inheritDoc
     */
    public function getDuration(): int
    {
        // TODO: Implement getDuration() method.
    }

    /**
     * @inheritDoc
     */
    public function getKind(): int
    {
        // TODO: Implement getKind() method.
    }

    /**
     * @inheritDoc
     */
    public function getAttribute(string $key)
    {
        // TODO: Implement getAttribute() method.
    }

    /**
     * @inheritDoc
     */
    public function getContext(): SpanContextInterface
    {
        // TODO: Implement getContext() method.
    }

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        // TODO: Implement isRecording() method.
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        // TODO: Implement setAttribute() method.
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        // TODO: Implement setAttributes() method.
    }

    /**
     * @inheritDoc
     */
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): SpanInterface
    {
        // no-op
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        // TODO: Implement recordException() method.
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function updateName(string $name): SpanInterface
    {
        // TODO: Implement updateName() method.
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null): SpanInterface
    {
        // TODO: Implement setStatus() method.
    }

    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null): void
    {
        // TODO: Implement end() method.
    }
}