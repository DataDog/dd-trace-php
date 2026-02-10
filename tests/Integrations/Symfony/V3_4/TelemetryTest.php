<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Integrations\Symfony\TelemetryTestSuite;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;


class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/index.php';
    }

    public static function getTestedLibrary()
    {
        return 'symfony/framework-bundle';
    }

    protected static function getTestedVersion($testedLibrary)
    {
        return '3.3.47';
    }
}
