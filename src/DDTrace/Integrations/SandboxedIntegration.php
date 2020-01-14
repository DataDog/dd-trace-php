<?php

namespace DDTrace\Integrations;

use DDTrace\Span;
use DDTrace\SpanData;
use DDTrace\Tag;

abstract class SandboxedIntegration extends Integration
{
    /**
     * Load the integration
     *
     * @return int
     */
    abstract public function init();

    public function addTraceAnalyticsIfEnabled(SpanData $span)
    {
        if (!$this->configuration->isTraceAnalyticsEnabled()) {
            return;
        }
        $span->metrics[Tag::ANALYTICS_KEY] = $this->configuration->getTraceAnalyticsSampleRate();
    }

    /**
     * Set integration name ONLY when running in a test.
     * Note: This is only for testing purposes and possibly temporary as we may want to add integration name
     * to the span's metadata in a consistent way across various tracers.
     *
     * @param DDTrace\SpanData $span
     * @return void
     */
    public function addIntegrationInfo(SpanData $span)
    {
        if (!in_array(getenv('DD_TEST_INTEGRATION'), ['1', 'true'])) {
            return;
        }

        $span->meta['integration.name'] = $this->getName();
    }

    /**
     * Sets common error tags for an exception.
     *
     * @param SpanData $span
     * @param \Exception $exception
     */
    public function setError(SpanData $span, \Exception $exception)
    {
        $span->meta[Tag::ERROR_MSG] = $exception->getMessage();
        $span->meta[Tag::ERROR_TYPE] = get_class($exception);
        $span->meta[Tag::ERROR_STACK] = $exception->getTraceAsString();
    }

    /**
     * Set a tag or alternatively a metric if the value is numeric and abs($value) < 2^53 .
     *
     * @param SpanData $span
     * @param string $name
     * @param mixed $value
     */
    public function setPotentialNumericTag(SpanData $span, $name, $value)
    {
        if (null === $value) {
            return;
        }

        if (!is_numeric($value)) {
            $span->meta[$name] = $value;
            return;
        }

        $asFloat = \floatval($value);
        if (\abs($asFloat) < Span::MAX_INT_AS_METRIC) {
            $span->metrics[$name] = $asFloat;
        } else {
            $span->meta[$name] = $value;
        }
    }

    /**
     * Merge an associative array of span metadata into a span.
     *
     * @param SpanData $span
     * @param array $meta
     */
    public function mergeMeta(SpanData $span, $meta)
    {
        foreach ($meta as $tagName => $value) {
            $span->meta[$tagName] = $value;
        }
    }
}
