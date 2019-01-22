<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Span;
use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\GlobalTracer;
use DDTrace\Log\Logger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Sampling\PrioritySampling;

final class Json implements Encoder
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: Logger::get();
    }

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(array $traces)
    {
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        return '[' . implode(',', array_map(function ($trace) use ($tracer) {
            return '[' . implode(',', array_filter(array_map(function ($span) use ($tracer) {
                return $this->encodeSpan($span, $tracer);
            }, $trace))) . ']';
        }, $traces))  . ']';
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'application/json';
    }

    /**
     * @param Span $span
     * @param Tracer $tracer
     * @return string
     */
    private function encodeSpan(Span $span, Tracer $tracer)
    {
        $json = json_encode($this->spanToArray($span, $tracer));
        if (false === $json) {
            $this->logger->debug("Failed to json-encode span: " . json_last_error_msg());
            return "";
        }

        return str_replace([
            '"start_micro":"-"',
            '"duration_micro":"-"',
            '"trace_id_hex":"-"',
            '"span_id_hex":"-"',
            '"parent_id_hex":"-"',
        ], [
            '"start":' . $span->getStartTime() . '000',
            '"duration":' . $span->getDuration() . '000',
            '"trace_id":' . $span->getTraceId(),
            '"span_id":' . $span->getSpanId(),
            '"parent_id":' . $span->getParentId(),
        ], $json);
    }

    /**
     * @param Span $span
     * @param Tracer $tracer
     * @return array
     */
    private function spanToArray(Span $span, Tracer $tracer)
    {
        $arraySpan = [
            'trace_id_hex' => '-',
            'span_id_hex' => '-',
            'name' => $span->getOperationName(),
            'resource' => $span->getResource(),
            'service' => $span->getService(),
            'start_micro' => '-',
            'error' => $span->hasError() ? 1 : 0,
        ];

        if ($span->getType() !== null) {
            $arraySpan['type'] = $span->getType();
        }

        if ($span->isFinished()) {
            $arraySpan['duration_micro'] = '-';
        }

        if ($span->getParentId() !== null) {
            $arraySpan['parent_id_hex'] = '-';
        }

        $tags = $span->getAllTags();
        if (!empty($tags)) {
            $arraySpan['meta'] = $tags;
        }

        if ($span->getContext()->isHostRoot()
                && ($prioritySampling = $tracer->getPrioritySampling()) !== PrioritySampling::UNKNOWN) {
            $arraySpan['metrics']['_sampling_priority_v1'] = $prioritySampling;
        }

        return $arraySpan;
    }
}
