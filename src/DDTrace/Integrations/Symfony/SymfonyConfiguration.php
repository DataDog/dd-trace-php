<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class SymfonyConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return SymfonyIntegration::NAME;
    }
}
