<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

class PatchSpec extends RequestSpec
{
    /**
     * @param string $name
     * @param string $path
     * @return PatchSpec
     */
    public static function create($name, $path, array $headers = [])
    {
        return new self($name, 'PATCH', $path, $headers);
    }
}
