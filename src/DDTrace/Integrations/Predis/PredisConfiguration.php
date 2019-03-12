<?php

namespace DDTrace\Integrations\Predis;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class PredisConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return PredisIntegration::NAME;
    }
}
