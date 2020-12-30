<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

class GetSpec extends RequestSpec
{
    /**
     * @param string $name
     * @param string $path
     * @return GetSpec
     */
    public static function create($name, $path, array $headers = [])
    {
        return new self($name, 'GET', $path, $headers);
    }
}
