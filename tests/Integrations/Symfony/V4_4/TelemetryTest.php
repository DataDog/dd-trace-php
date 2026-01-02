<?php

namespace DDTrace\Tests\Integrations\Symfony\V4_4;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_4_4/public/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }
}
