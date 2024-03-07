<?php

namespace DDTrace\Tests\Integrations\WordPress\V6_1;

class CommonScenariosCallbacksTest extends CommonScenariosTest
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'wordpress_61_test_app',
            'DD_TRACE_WORDPRESS_CALLBACKS' => '1',
            'DD_TRACE_MYSQLI_ENABLED' => '0'
        ]);
    }
}
