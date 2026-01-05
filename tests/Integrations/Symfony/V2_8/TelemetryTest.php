<?php

namespace DDTrace\Tests\Integrations\Symfony\V2_8;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;


class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_2_8/web/app.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected function getBase()
    {
        return '/app.php';
    }
}
