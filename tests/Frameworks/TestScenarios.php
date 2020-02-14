<?php

namespace DDTrace\Tests\Frameworks;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class TestScenarios
{
    public static function all()
    {
        return [
            GetSpec::create('A simple GET request returning a string', '/simple'),
            GetSpec::create('A simple GET request with a view', '/simple_view'),
            GetSpec::create('A GET request with an exception', '/error')->expectStatusCode(500),
            GetSpec::create('A GET request ended in http_status_code(200)', '/http_response_code/success'),
            GetSpec::create('A GET request ended in http_status_code(500)', '/http_response_code/failure')
                ->expectStatusCode(500),
        ];
    }
}
