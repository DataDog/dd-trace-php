<?php

namespace DDTrace\Tests\Frameworks;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class TestScenarios
{
    public static function all()
    {
        return [
            GetSpec::create('A simple GET request returning a string', '/simple?key=value&pwd=should_redact'),
            GetSpec::create('A simple GET request with a view', '/simple_view?key=value&pwd=should_redact'),
            GetSpec::create(
                'A GET request with an exception',
                '/error?key=value&pwd=should_redact'
            )->expectStatusCode(500),
            GetSpec::create('A GET request to a missing route', '/does_not_exist?key=value&pwd=should_redact'),
            GetSpec::create('A call to healthcheck', '/health_check/ping'),
        ];
    }
}
