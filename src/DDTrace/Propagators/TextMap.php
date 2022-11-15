<?php

namespace DDTrace\Propagators;

use DDTrace\Log\LoggingTrait;
use DDTrace\Propagator;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer;
use DDTrace\SpanContext;

final class TextMap implements Propagator
{
    use LoggingTrait;

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
    public function inject(SpanContextInterface $spanContext, &$carrier)
    {
        $carrier[Propagator::DEFAULT_TRACE_ID_HEADER] = $spanContext->getTraceId();
        $carrier[Propagator::DEFAULT_PARENT_ID_HEADER] = $spanContext->getSpanId();

        foreach ($spanContext as $key => $value) {
            $carrier[Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX . $key] = $value;
        }

        $prioritySampling = $this->tracer->getPrioritySampling();
        if (PrioritySampling::UNKNOWN !== $prioritySampling) {
            $carrier[Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER] = $prioritySampling;
        }
        if (!empty($spanContext->origin)) {
            $carrier[Propagator::DEFAULT_ORIGIN_HEADER] = $spanContext->origin;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        $traceId = '';
        $spanId = '';
        $baggageItems = [];

        foreach ($carrier as $key => $value) {
            if ($key === Propagator::DEFAULT_TRACE_ID_HEADER) {
                $traceId = $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === Propagator::DEFAULT_PARENT_ID_HEADER) {
                $spanId = $this->extractStringOrFirstArrayElement($value);
            } elseif (strpos($key, Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX) === 0) {
                $baggageItems[substr($key, strlen(Propagator::DEFAULT_BAGGAGE_HEADER_PREFIX))] = $value;
            }
        }

        if (
            preg_match('/^\d+$/', $traceId) !== 1 ||
            preg_match('/^\d+$/', $spanId) !== 1
        ) {
            return null;
        }

        $spanContext = new SpanContext($traceId, $spanId ?: '', null, $baggageItems, true);
        $this->extractPrioritySampling($spanContext, $carrier);
        $this->extractOrigin($spanContext, $carrier);
        return $spanContext;
    }

    /**
     * A utility function to mitigate differences between how headers are provided by various web frameworks.
     * E.g. in both the cases that follow, this method would return 'application/json':
     *   1) as array of values: ['content-type' => ['application/json']]
     *   2) as string value: ['content-type' => 'application/json']
     *
     * @param array|string $value
     * @return string|null
     */
    private function extractStringOrFirstArrayElement($value)
    {
        $singleValue = $this->extractScalarOrFirstArrayElement($value);
        if (is_string($singleValue)) {
            return $singleValue;
        }
        return null;
    }

    private function extractScalarOrFirstArrayElement($value)
    {
        if (is_array($value) && count($value) > 0) {
            return $value[0];
        } elseif (is_scalar($value)) {
            return $value;
        }
        return null;
    }

    /**
     * Extract from carrier the propagated priority sampling.
     *
     * @param SpanContextInterface $spanContext
     * @param array $carrier
     */
    private function extractPrioritySampling(SpanContextInterface $spanContext, $carrier)
    {
        if (isset($carrier[Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER])) {
            $rawValue = $this->extractScalarOrFirstArrayElement($carrier[Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER]);
            $spanContext->setPropagatedPrioritySampling(PrioritySampling::parse($rawValue));
        }
    }

    /**
     * Extract the origin from the carrier.
     *
     * @param SpanContextInterface $spanContext
     * @param array $carrier
     */
    private function extractOrigin(SpanContextInterface $spanContext, $carrier)
    {
        if (
            property_exists($spanContext, 'origin')
            && isset($carrier[Propagator::DEFAULT_ORIGIN_HEADER])
        ) {
            $spanContext->origin = $this->extractStringOrFirstArrayElement($carrier[Propagator::DEFAULT_ORIGIN_HEADER]);
        }
    }
}
