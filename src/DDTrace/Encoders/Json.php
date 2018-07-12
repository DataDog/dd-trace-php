<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;
use DDTrace\Span;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class Json implements Encoder
{
    use LoggerAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(array $traces)
    {
        return '[' . implode(',', array_map(function ($trace) {
            return '[' . implode(',', array_filter(array_map(function ($span) {
                return $this->encodeSpan($span);
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
     * @return string
     */
    private function encodeSpan(Span $span)
    {
        $json = json_encode($this->spanToArray($span));
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
            '"trace_id":' . $this->hex2dec($span->getTraceId()),
            '"span_id":' . $this->hex2dec($span->getSpanId()),
            '"parent_id":' . $this->hex2dec($span->getParentId()),
        ], $json);
    }

    /**
     * @param string $hex
     * @return string
     */
    private function hex2dec($hex)
    {
        return base_convert($hex, 16, 10);
    }

    /**
     * @param Span $span
     * @return array
     */
    private function spanToArray(Span $span)
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

        if (!empty($span->getAllTags())) {
            $arraySpan['meta'] = $span->getAllTags();
        }

        return $arraySpan;
    }
}
