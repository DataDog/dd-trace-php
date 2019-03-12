<?php

namespace DDTrace\Integrations\ZendFramework;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class ZendFrameworkConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return ZendFrameworkIntegration::NAME;
    }
}
