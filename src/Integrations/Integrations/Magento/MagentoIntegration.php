<?php

namespace DDTrace\Integrations\Magento;

use DDTrace\Integrations\Integration;

class MagentoIntegration extends Integration
{
    const NAME = 'magento';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        return Integration::LOADED;
    }
}
