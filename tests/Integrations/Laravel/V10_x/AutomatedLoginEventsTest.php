<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

use DDTrace\Tests\Integrations\Laravel\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "laravel10";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_SERVICE' => 'my_service'
        ]);
    }
}
