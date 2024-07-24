<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_3;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_3/web/index.php';
    }
}
