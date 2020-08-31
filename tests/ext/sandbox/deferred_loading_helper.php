<?php

namespace DDTrace\Integrations
{
    abstract class Integration
    {
        const LOADED = 1;

        abstract function init();
    }

    function load_deferred_integration($integrationName)
    {
        assert(\is_subclass_of($integrationName, 'DDTrace\\Integrations\\Integration'));
        $integration = new $integrationName();
        assert($integration->init() == Integration::LOADED);
    }
}

