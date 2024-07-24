<?php

namespace DDTrace\Tests\Integrations\Symfony\V6_2;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_6_2/public/index.php';
    }
}
