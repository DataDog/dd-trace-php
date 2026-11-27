<?php

namespace DDTrace\Tests\Integrations\Slim\V3_12;

use DDTrace\Tests\Integrations\Slim\TelemetryTestSuite;

class TelemetryTest extends TelemetryTestSuite
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_3_12/public/index.php';
    }

    // Mirrors tests/Frameworks/Slim/Version_3_12/src/routes.php.
    protected function expectedEndpoints()
    {
        return [
            ['/simple', 'GET'],
            ['/simple_view', 'GET'],
            ['/error', 'GET'],
            ['/parameterized/{value}', 'GET'],
            ['/dynamic-path/{param01}', 'GET'],
            ['/dynamic_route/{param01}[/static/{param02}]', 'GET'],
            ['/multi-method', 'GET'],
            ['/multi-method', 'POST'],
            ['/telemetry', 'GET'],
            ['/[{name}]', 'GET'],
        ];
    }
}
