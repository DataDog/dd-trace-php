<?php

namespace DDTrace\Tests\Integrations\WordPress\V6_1;

use DDTrace\Tests\Integrations\WordPress\V6_1\CommonScenariosTest;

class CommonScenariosLegacyTest extends CommonScenariosTest
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_WORDPRESS_ENHANCED_INTEGRATION' => '0'
        ]);
    }
}
