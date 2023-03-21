<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

class PostSpec extends RequestSpec
{
    public static function create($name, $path, array $headers = [], $body = [])
    {
        return new self($name, 'POST', $path, $headers, $body);
    }
}
