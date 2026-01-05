<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_3;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_3/web/app.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '2.3.42';
    }

    protected function getBase()
    {
        return '/app.php';
    }
}
