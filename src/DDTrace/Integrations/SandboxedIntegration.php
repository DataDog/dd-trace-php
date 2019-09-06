<?php

namespace DDTrace\Integrations;

use DDTrace\SpanData;
use DDTrace\Tag;

abstract class SandboxedIntegration extends Integration
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function get()
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }
        return self::$instance;
    }

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
