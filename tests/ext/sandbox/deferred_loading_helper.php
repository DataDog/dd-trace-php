<?php

namespace DDTrace\Integrations
{
    abstract class SandboxedIntegration
    {
        const LOADED = 1;

        abstract function init();
    }

    function load_deferred_integration($integrationName)
    {
        assert(\is_subclass_of($integrationName, 'DDTrace\\Integrations\\SandboxedIntegration'));
        $integration = new $integrationName();
        assert($integration->init() == SandboxedIntegration::LOADED);
    }
}

