<?php

namespace DDTrace\Encoders;

use DDTrace\Span;
use DDTrace\Data\Span as DataSpan;
use DDTrace\Log\LoggingTrait;
use DDTrace\Tag;

/** @deprecated Obsoleted by moving related code to internal. */
final class SpanEncoder
{
    use LoggingTrait;

    /**
     * @param DataSpan $span
     * @return array
     */
    public static function encode(DataSpan $span)
    {
        self::logSpanDetailsIfDebug($span);

        $arraySpan = [
            'trace_id' => $span->context->traceId,
            'span_id' => $span->context->spanId,
            'name' => $span->operationName,
            'resource' => $span->resource,
            'service' => $span->service,
            'start' => (int) ($span->startTime . '000'),
            'error' => $span->hasError ? 1 : 0,
        ];

        if ($span->type !== null) {
            $arraySpan['type'] = $span->type;
        }

        if ($span->duration !== null) { // is span finished ?
            $arraySpan['duration'] = (int) ($span->duration . '000');
        }

        if ($span->context->parentId !== null) {
            $arraySpan['parent_id'] = $span->context->parentId;
        }

        $tags = $span->tags;
        if (
            !empty($span->context->origin)
            && $span->context->isHostRoot()
        ) {
            $tags[Tag::ORIGIN] = $span->context->origin;
        }
        if (!empty($tags)) {
            $arraySpan['meta'] = $tags;
        }

        // Handling metrics
        $metrics = [];
        foreach ($span->metrics as $metricName => $metricValue) {
            $metrics[$metricName] = $metricValue;
        }
        if ($span->context->isHostRoot()) {
            if (\dd_trace_env_config('DD_TRACE_MEASURE_COMPILE_TIME')) {
                // Metric expects milliseconds
                $metrics['php.compilation.total_time_ms'] = (float) dd_trace_compile_time_microseconds() / 1000;
            }
        }
        if (!empty($metrics)) {
            $arraySpan['metrics'] = $metrics;
        }

        return $arraySpan;
    }

    /**
     * Logs a Span's detailed info.
     *
     * @param Span $span
     */
    private static function logSpanDetailsIfDebug(Span $span)
    {
        if (!self::isLogDebugActive()) {
            return;
        }

        $lengths = [];
        foreach ($span->getAllTags() as $tagName => $tagValue) {
            $lengths[] = "$tagName:" . strlen($tagValue);
        }

        self::logDebug(
            "Encoding span '{id}' op: '{operation}' serv: '{service}' res: '{resource}' type '{type}'",
            [
                'id' => $span->getSpanId(),
                'operation' => $span->getOperationName(),
                'service' => $span->getService(),
                'resource' => $span->getResource(),
                'type' => $span->getType(),
            ]
        );
        self::logDebug('Tags for span {id} \'tag:chars_count\' are: {lengths}', [
            'id' => $span->getSpanId(),
            'lengths' => implode(',', $lengths),
        ]);
    }
}
