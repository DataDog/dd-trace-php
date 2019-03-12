<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class MongoConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return MongoIntegration::NAME;
    }
}
