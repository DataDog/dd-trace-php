<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use DDTrace\SpanData;
use DDTrace\Tag;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use Throwable;

use function DDTrace\close_span;

final class Span extends API\Span implements ReadWriteSpanInterface
{
    private SpanData $span;

    private bool $hasEnded;

    private API\SpanContextInterface $context;

    private API\SpanContextInterface $parentContext;

    private int $kind;

    private string $statusCode;

    private InstrumentationScopeInterface $instrumentationScope;

    public function getName(): string
    {
        return $this->span->name;
    }

    public function getParentContext(): API\SpanContextInterface
    {
        return $this->parentContext;
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }

    public function hasEnded(): bool
    {
        return $this->hasEnded;
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
        return $this->span->getDuration();
    }

    /**
     * @inheritDoc
     */
    public function getKind(): int
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getAttribute(string $key)
    {
        $meta = $this->span->meta;

        if (isset($meta[$key])) {
            return $meta[$key];
        }

        if (empty($meta)) {
            return null;
        }

        // Support for nested attributes through dot notation
        $prefix = '';
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            $prefix .= $part;
            if (isset($meta[$prefix])) {
                return $meta[$prefix];
            } else {
                $prefix .= '.';
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        return !$this->hasEnded;
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->meta[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
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
        // no-op
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function updateName(string $name): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->name = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null): SpanInterface
    {
        if ($this->hasEnded) {
            return $this;
        }

        if ($this->statusCode === API\StatusCode::STATUS_UNSET && $code === API\StatusCode::STATUS_ERROR) {
            $this->statusCode = $code;
            $this->span->meta[Tag::ERROR_MSG] = $description;
        } elseif ($this->statusCode === API\StatusCode::STATUS_ERROR && $code === API\StatusCode::STATUS_OK) {
            $this->statusCode = $code;
            unset($this->span->meta[Tag::ERROR_MSG]);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null): void
    {
        if ($this->hasEnded) {
            return;
        }

        // TODO: Actually check if the span was closed (change extension to return a boolean?)
        close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
        $this->hasEnded = true;
    }
}