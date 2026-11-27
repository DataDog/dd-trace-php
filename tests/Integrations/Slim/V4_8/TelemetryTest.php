<?php

namespace DDTrace\Tests\Integrations\Slim\V4_8;

use DDTrace\Tests\Integrations\Slim\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_4_8/public/index.php';
    }

    // Mirrors tests/Frameworks/Slim/Version_4_8/app/routes.php.
    protected function expectedEndpoints()
    {
        return [
            ['/{routes:.*}', 'OPTIONS'],
            ['/', 'GET'],
            ['/users', 'GET'],
            ['/users/{id}', 'GET'],
            ['/simple', 'GET'],
            ['/simple_view', 'GET'],
            ['/error', 'GET'],
            ['/parameterized/{value}', 'GET'],
            ['/dynamic-path/{param01}', 'GET'],
            ['/dynamic_route/{param01}[/static/{param02}]', 'GET'],
            ['/multi-method', 'GET'],
            ['/multi-method', 'POST'],
            ['/telemetry', 'GET'],
        ];
    }
}
