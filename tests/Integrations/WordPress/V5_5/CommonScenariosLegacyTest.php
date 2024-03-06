<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Integrations\WordPress\V5_5\CommonScenariosTest;

class CommonScenariosLegacyTest extends CommonScenariosTest
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_WORDPRESS_ENHANCED_INTEGRATION' => '0',
            'DD_TRACE_MYSQLI_ENABLED' => '0'
        ]);
    }
}
