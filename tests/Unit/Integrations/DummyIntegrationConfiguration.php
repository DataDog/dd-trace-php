<?php

namespace DDTrace\Tests\Unit\Integrations;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

class DummyIntegrationConfiguration extends AbstractIntegrationConfiguration
{
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
