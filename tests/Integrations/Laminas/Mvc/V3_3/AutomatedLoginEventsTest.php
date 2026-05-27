<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc\V3_3;

use DDTrace\Tests\Integrations\Laminas\Mvc\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "test";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/Mvc/Version_3_3/public/index.php';
    }
}
