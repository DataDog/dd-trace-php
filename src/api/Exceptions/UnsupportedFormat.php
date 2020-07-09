<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Exceptions/UnsupportedFormat.php
 */

namespace DDTrace\Exceptions;

use UnexpectedValueException;

/**
 * Thrown when trying to inject or extract in an invalid format
 */
final class UnsupportedFormat extends UnexpectedValueException
{
    /**
     * @param string $format
     * @return UnsupportedFormat
     */
    public static function forFormat($format)
    {
        return new self(sprintf('The format \'%s\' is not supported.', $format));
    }
}
