<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_0;

class AutomatedLoginEventsTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\AutomatedLoginEventsTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_0/public/index.php';
    }
}
