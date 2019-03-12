<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class LaravelConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return LaravelIntegration::NAME;
    }
}
