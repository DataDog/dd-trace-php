<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

class DummyIntegrationConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name
     */
    public function __construct($name)
    {
        $this->name = $name;
        parent::__construct();
    }

    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return $this->name;
    }

    /**
     * @return float
     */
    public function getSampleBool()
    {
        return $this->boolValue('sample.bool', true);
    }

    /**
     * @return float
     */
    public function getSampleFloat()
    {
        return $this->floatValue('sample.float', 1.23);
    }
}
