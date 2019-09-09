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
}
