<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_0;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/public/index.php';
    }
}
