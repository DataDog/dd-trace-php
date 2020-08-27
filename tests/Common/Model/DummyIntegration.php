<?php

namespace DDTrace\Tests\Common\Model;

use DDTrace\Integrations\Integration;

/**
 * Dummy integration class that can be easily configured.
 */
final class DummyIntegration extends Integration
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $traceAnalyticsEnabled = false;

    /**
     * @var float
     */
    private $traceAnalyticsSampleRate = 1.0;

    /**
     * DummyIntegration constructor.
     * @param $name
     */
    public function __construct($name)
    {
        parent::__construct();
        $this->name = $name;
    }

    /**
     * @param string $name
     * @return DummyIntegration
     */
    public static function create($name = 'dummy')
    {
        return new self($name);
    }

    /**
     * @param bool $enabled
     * @param float $sampleRate
     * @return $this
     */
    public function withTraceAnalyticsConfiguration($enabled = true, $sampleRate = 1.0)
    {
        $this->traceAnalyticsEnabled = $enabled;
        $this->traceAnalyticsSampleRate = $sampleRate;
        return $this;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function isTraceAnalyticsEnabled()
    {
        return $this->traceAnalyticsEnabled;
    }

    /**
     * {@inheritdoc}
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->traceAnalyticsSampleRate;
    }
}
