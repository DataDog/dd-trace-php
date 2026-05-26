<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc\Latest;

use DDTrace\Tests\Integrations\Laminas\Mvc\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "test";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../../Frameworks/Laminas/Mvc/Latest/public/index.php';
    }
}
