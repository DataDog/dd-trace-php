<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class PDOConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return PDOIntegration::NAME;
    }
}
