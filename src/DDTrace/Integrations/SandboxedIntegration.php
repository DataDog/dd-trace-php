<?php

namespace DDTrace\Integrations;

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
