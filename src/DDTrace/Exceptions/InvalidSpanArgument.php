<?php

namespace DDTrace\Exceptions;

use InvalidArgumentException;

final class InvalidSpanArgument extends InvalidArgumentException
{
    public static function forTagKey($key)
    {
        return new self(
            sprintf('Invalid key type in given span tags. Expected string, got %s.', gettype($key))
        );
    }

    public static function forError($error)
    {
        return new self(
            sprintf('Error should be either Exception or Throwable, got %s.', gettype($error))
        );
    }
}
