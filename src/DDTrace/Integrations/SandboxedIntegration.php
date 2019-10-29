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
     * @param string $message
     */
    public function setError(SpanData $span, $message)
    {
        $span->meta[Tag::ERROR_MSG] = $message;
        $span->error = 1;
    }
}
