<?php

namespace DDTrace\Propagators;

use DDTrace\Propagator;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Contracts\SpanContext;
use DDTrace\Contracts\Tracer;

/**
 * A propagator that inject distributed tracing context in curl like indexed headers arrays:
 * ['header1: value1', 'header2: value2']
 */
final class CurlHeadersMap implements Propagator
{
    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @param Tracer $tracer
     */
    public function __construct(Tracer $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContext $spanContext, &$carrier)
    {
        foreach ($carrier as $index => $value) {
            if (substr($value, 0, strlen(Propagator::DEFAULT_TRACE_ID_HEADER))
                    === Propagator::DEFAULT_TRACE_ID_HEADER
            ) {
                unset($carrier[$index]);
            } elseif (substr($value, 0, strlen(Propagator::DEFAULT_PARENT_ID_HEADER))
                    === Propagator::DEFAULT_PARENT_ID_HEADER
            ) {
                unset($carrier[$index]);
            } elseif (substr($value, 0, strlen(Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX))
                    === Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX
            ) {
                unset($carrier[$index]);
            } elseif (substr($value, 0, strlen(Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER))
                === Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER
            ) {
                unset($carrier[$index]);
            }
        }

        $carrier[] = Propagator::DEFAULT_TRACE_ID_HEADER . ': ' . $spanContext->getTraceId();
        $carrier[] = Propagator::DEFAULT_PARENT_ID_HEADER . ': ' . $spanContext->getSpanId();

        foreach ($spanContext as $key => $value) {
            $carrier[] = Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX . $key . ': ' . $value;
        }

        $prioritySampling = $this->tracer->getPrioritySampling();
        if (PrioritySampling::UNKNOWN !== $prioritySampling) {
            $carrier[] = Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER . ': ' . $prioritySampling;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        // This use case is not implemented as we haven't found any framework returning headers in curl style so far.
    }
}
