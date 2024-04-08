<?php

namespace DDTrace\Integrations\CakePHP;

use DDTrace\Integrations\CakePHP\V2\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV2;
use DDTrace\Integrations\CakePHP\V3\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV3;
use DDTrace\Integrations\Integration;

class CakePHPIntegration extends Integration
{
    const NAME = 'cakephp';

    public $appName;
    public $rootSpan;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    // CakePHP v2.x - we don't need to check for v3 since it does not have \Dispatcher or \ShellDispatcher
    public function init(): int
    {
        $integration = $this;

        // Since "Dispatcher" and "App" are common names, check for a CakePHP signature before loading
        /*
        if (!defined('CAKE_CORE_INCLUDE_PATH')) {
            return self::NOT_AVAILABLE;
        }
        */

        $loader = class_exists('Cake\Http\Server') // Only exists in V3+
            ? new CakePHPIntegrationLoaderV3()
            : new CakePHPIntegrationLoaderV2();
        $loader->load($integration);

        return Integration::LOADED;
    }
}
