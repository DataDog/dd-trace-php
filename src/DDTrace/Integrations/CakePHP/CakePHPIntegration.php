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

    public function init(): int
    {
        $integration = $this;

        $loader = class_exists('Cake\Http\Server') // Only exists in V3+
            ? new CakePHPIntegrationLoaderV3()
            : new CakePHPIntegrationLoaderV2();
        return $loader->load($integration);
    }
}
