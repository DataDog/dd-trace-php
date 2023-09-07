<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_9;

final class CommonScenariosCallbacksTest extends CommonScenariosTest
{
    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_WORDPRESS_CALLBACKS' => '1'
        ]);
    }
}
