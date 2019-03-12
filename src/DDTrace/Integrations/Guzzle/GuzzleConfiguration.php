<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class GuzzleConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return GuzzleIntegration::NAME;
    }
}
