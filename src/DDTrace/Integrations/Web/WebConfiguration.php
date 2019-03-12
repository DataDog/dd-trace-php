<?php

namespace DDTrace\Integrations\Web;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class WebConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return WebIntegration::NAME;
    }
}
