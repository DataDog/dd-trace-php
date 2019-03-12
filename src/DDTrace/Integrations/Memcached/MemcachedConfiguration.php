<?php

namespace DDTrace\Integrations\Memcached;

use DDTrace\Integrations\AbstractIntegrationConfiguration;

final class MemcachedConfiguration extends AbstractIntegrationConfiguration
{
    /**
     * @return string The integration name this configuration refers to.
     */
    public function getIntegrationName()
    {
        return MemcachedIntegration::NAME;
    }
}
