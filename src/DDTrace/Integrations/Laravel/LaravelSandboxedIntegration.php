<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Laravel\V5\LaravelIntegrationLoader;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Util\Versions;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'laravel';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * @return int
     */
    public function init()
    {
        $kernelClass = null;
        return SandboxedIntegration::LOADED;
    }
}
