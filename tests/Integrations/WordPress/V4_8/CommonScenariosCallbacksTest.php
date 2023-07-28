<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

class CommonScenariosCallbacksTest extends CommonScenariosTest
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'wordpress_test_app',
            'DD_TRACE_WORDPRESS_CALLBACKS' => '1'
        ]);
    }
}
