<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class EloquentConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return EloquentIntegration::NAME;
    }
}
