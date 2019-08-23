<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\SpanContext as DDSpanContext;
use OpenTracing\SpanContext as OTSpanContext;

final class SpanContext implements OTSpanContext
{
    /**
     * @var SpanContextInterface
     */
    private $context;

    /**
     * @param SpanContextInterface $context
     */
    public function __construct(SpanContextInterface $context)
    {
        $this->context = $context;
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return $this->context->getBaggageItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function withBaggageItem($key, $value)
    {
        return $this->context->withBaggageItem($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return $this->context->getIterator();
    }

    /**
     * @return SpanContextInterface
     */
    public function unwrapped()
    {
        return $this->context;
    }

    /**
     * Converts an OpenTracing SpanContext instance to a DD one
     *
     * @param OTSpanContext $otContext
     * @return DDSpanContext
     */
    public static function toDDSpanContext(OTSpanContext $otContext)
    {
        $baggage = [];
        foreach ($otContext as $key => $value) {
            $baggage[$key] = $value;
        }
        return new DDSpanContext(
            // Since the OT interface doesn't give us access to the
            // trace and span ID's, we need to regenerate them
            // Note: We can't use `dd_trace_push_span_id()` here since
            // it could break non-OpenTracing spans
            mt_rand(1, mt_getrandmax()),
            mt_rand(1, mt_getrandmax()),
            null,
            $baggage
        );
    }
}
