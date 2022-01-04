<?php

namespace Zai\Methods;

class Test
{
    private $trueValue = true;

    public function returnsTrue()
    {
        return true;
    }

    public function usesThis()
    {
        return $this->trueValue;
    }

    public static function returns42()
    {
        return 42;
    }

    public static function newSelf()
    {
        return new static;
    }

    public function returnsArg($arg)
    {
        return $arg;
    }
}
