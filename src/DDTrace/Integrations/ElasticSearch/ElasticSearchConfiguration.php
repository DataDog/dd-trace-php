<?php

namespace DDTrace\Integrations\ElasticSearch;

use DDTrace\Integrations\AbstractIntegrationConfiguration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;

final class ElasticSearchConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return ElasticSearchIntegration::NAME;
    }
}
