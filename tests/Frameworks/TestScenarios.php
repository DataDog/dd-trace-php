<?php

namespace DDTrace\Tests\Frameworks;

use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class TestScenarios
{
    public static function all()
    {
        return [
            GetSpec::create('A simple GET request returning a string', '/simple?should_be=removed'),
            GetSpec::create('A simple GET request with a view', '/simple_view?should_be=removed'),
            GetSpec::create('A GET request with an exception', '/error?should_be=removed')->expectStatusCode(500),
            GetSpec::create('A GET request to a missing route', '/does_not_exist?should_be=removed'),
        ];
    }
}
