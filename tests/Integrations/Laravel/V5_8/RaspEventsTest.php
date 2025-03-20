<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

use DDTrace\Tests\Integrations\Laravel\RaspEventsTestSuite;

/**
 * @group appsec
 */
class RaspEventsTest extends RaspEventsTestSuite
{
    public static $database = "laravel58";

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_DEBUG' => false
        ]);
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }
}
