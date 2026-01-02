<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_0;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_0/web/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '3.0.9';
    }
}
