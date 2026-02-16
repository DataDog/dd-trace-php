<?php

namespace DDTrace\Tests\Integrations\Symfony\V5_0;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_5_0/public/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }
}
