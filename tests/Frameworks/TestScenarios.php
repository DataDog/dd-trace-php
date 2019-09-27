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
        ];
    }
}
