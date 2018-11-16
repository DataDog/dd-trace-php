<?php

namespace DDTrace\Tests\Integration\Frameworks;

use DDTrace\Tests\Integration\Frameworks\Util\Request\GetSpec;


class TestSpecs
{
    public static function all()
    {
        return [
            GetSpec::create('A simple GET request returning a string', '/simple'),
            GetSpec::create('A simple GET request with a view', '/simple_view'),
        ];
    }
}
