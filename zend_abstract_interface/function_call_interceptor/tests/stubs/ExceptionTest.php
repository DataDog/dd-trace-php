<?php

namespace Zai\Methods;

class ExceptionTest
{
    private $message = 'Oops!';
    private static $staticMessage = 'Oops!';

    public function throwsException()
    {
        throw new \Exception($this->message);
    }

    public static function throwsExceptionFromStatic()
    {
        throw new \Exception(static::$staticMessage);
    }
}
