<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\Log\LoggingTrait;
use DDTrace\Sampling\PrioritySampling;

final class SpanEncoder
{
    use LoggingTrait;

    /**
     * @param Span $span
     * @return array
     */
    public static function encode(Span $span)
    {
        self::logSpanDetailsIfDebug($span);

        $arraySpan = [
            'trace_id' => (int) $span->getTraceId(),
            'span_id' => (int) $span->getSpanId(),
            'name' => $span->getOperationName(),
            'resource' => $span->getResource(),
            'service' => $span->getService(),
            'start' => (int) ($span->getStartTime() . '000'),
            'error' => $span->hasError() ? 1 : 0,
        ];

        if ($span->getType() !== null) {
            $arraySpan['type'] = $span->getType();
        }

        if ($span->isFinished()) {
            $arraySpan['duration'] = (int) ($span->getDuration() . '000');
        }

        if ($span->getParentId() !== null) {
            $arraySpan['parent_id'] = (int) $span->getParentId();
        }

        $tags = $span->getAllTags();
        if (!empty($tags)) {
            $arraySpan['meta'] = $tags;
        }

        // Handling metrics
        $metrics = [];
        foreach ($span->getMetrics() as $metricName => $metricValue) {
            $metrics[$metricName] = $metricValue;
        }
        if ($span->getContext()->isHostRoot()
                && ($prioritySampling = GlobalTracer::get()->getPrioritySampling()) !== PrioritySampling::UNKNOWN) {
            $metrics['_sampling_priority_v1'] = $prioritySampling;
        }
        if (!empty($metrics)) {
            $arraySpan['metrics'] = $metrics;
        }

        // This is only for testing purposes and possibly temporary as we may want to add integration name to the span's
        // metadata in a consistent way across various tracers.
        if (null !== $span->getIntegration()
                && false !== ($integrationTest = getenv('DD_TEST_INTEGRATION'))
                && in_array($integrationTest, ['1', 'true'])) {
            $arraySpan['meta']['integration.name'] = $span->getIntegration()->getName();
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
