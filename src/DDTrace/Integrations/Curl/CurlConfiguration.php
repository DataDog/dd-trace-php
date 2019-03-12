<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class CurlConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return CurlIntegration::NAME;
    }
}
