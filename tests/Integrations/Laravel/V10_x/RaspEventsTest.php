<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

use DDTrace\Tests\Integrations\Laravel\RaspEventsTestSuite;

/**
 * @group appsec
 */
class RaspEventsTest extends RaspEventsTestSuite
{
    public static $database = "laravel10";

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_DEBUG' => false
        ]);
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
    }
}
