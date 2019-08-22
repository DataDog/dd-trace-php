<?php

namespace DDTrace\Encoders;

use DDTrace\Span;
use DDTrace\Data\Span as DataSpan;
use DDTrace\GlobalTracer;
use DDTrace\Log\LoggingTrait;
use DDTrace\Sampling\PrioritySampling;

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
            'trace_id' => (int) $span->context->traceId,
            'span_id' => (int) $span->context->spanId,
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
            $arraySpan['parent_id'] = (int) $span->context->parentId;
        }

        $tags = $span->tags;
        if (!empty($tags)) {
            $arraySpan['meta'] = $tags;
        }

        // Handling metrics
        $metrics = [];
        foreach ($span->metrics as $metricName => $metricValue) {
            $metrics[$metricName] = $metricValue;
        }
        if ($span->context->isHostRoot()
                && ($prioritySampling = GlobalTracer::get()->getPrioritySampling()) !== PrioritySampling::UNKNOWN) {
            $metrics['_sampling_priority_v1'] = $prioritySampling;
        }
        if (!empty($metrics)) {
            $arraySpan['metrics'] = $metrics;
        }

        // This is only for testing purposes and possibly temporary as we may want to add integration name to the span's
        // metadata in a consistent way across various tracers.
        if (null !== $span->integration
                && false !== ($integrationTest = getenv('DD_TEST_INTEGRATION'))
                && in_array($integrationTest, ['1', 'true'])) {
            $arraySpan['meta']['integration.name'] = $span->integration->getName();
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
