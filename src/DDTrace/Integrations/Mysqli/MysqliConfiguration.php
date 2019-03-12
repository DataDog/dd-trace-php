<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class MysqliConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return MysqliIntegration::NAME;
    }
}
