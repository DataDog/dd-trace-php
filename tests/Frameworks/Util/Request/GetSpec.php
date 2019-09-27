<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

class GetSpec extends RequestSpec
{
    /**
     * @param string $name
     * @param string $path
     */
    protected function __construct($name, $path)
    {
        parent::__construct($name, 'GET', $path);
    }

    /**
     * @param string $name
     * @param string $path
     * @return GetSpec
     */
    public static function create($name, $path)
    {
        return new GetSpec($name, $path);
    }
}
