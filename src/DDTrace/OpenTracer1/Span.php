<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Span as DDSpan;
use OpenTracing\Span as OTSpan;
use OpenTracing\SpanContext as OTSpanContext;

final class Span implements OTSpan
{
    /**
     * @var SpanInterface
     */
    private $span;

    /**
     * @var SpanContext
     */
    private $context;

    /**
     * @param SpanInterface $span
     */
    public function __construct(SpanInterface $span)
    {
        $this->span = $span;
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName(string $operationName): void
    {
        $this->span->overwriteOperationName($operationName);
    }

    /**
     * {@inheritdoc}
     */
    public function setTag(string $key, $value): void
    {
        $this->span->setTag($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null): void
    {
        $this->span->finish($finishTime);
    }

    /**
     * {@inheritdoc}
     */
    public function getOperationName(): string
    {
        return $this->span->getOperationName();
    }

    /**
     * {@inheritdoc}
     */
    public function getContext(): OTSpanContext
    {
        if (isset($this->context)) {
            return $this->context;
        }
        return $this->context = new SpanContext(
            $this->span->getContext()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function log(array $fields = [], $timestamp = null): void
    {
        $this->span->log($fields, $timestamp);
    }

    /**
     * {@inheritdoc}
     */
    public function addBaggageItem(string $key, string $value): void
    {
        $this->span->addBaggageItem($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem(string $key): ?string
    {
        return $this->span->getBaggageItem($key);
    }

    /**
     * @return SpanInterface
     */
    public function unwrapped()
    {
        return $this->span;
    }

    /**
     * Converts an OpenTracing Span instance to a DD one
     *
     * @param OTSpan $otSpan
     * @return DDSpan
     */
    public static function toDDSpan(OTSpan $otSpan)
    {
        return new DDSpan(
            $otSpan->getOperationName(),
            SpanContext::toDDSpanContext($otSpan->getContext()),
            // Since we don't have access to span tags, we use defaults
            // for "service" and "resource".
            PHP_SAPI,
            $otSpan->getOperationName()
        );
    }
}
