<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Exceptions/InvalidReferencesSet.php
 */

namespace DDTrace\Exceptions;

use DomainException;

/**
 * Thrown when a reference has more than one parent in the SpanOptions
 */
final class InvalidReferencesSet extends DomainException
{
    /**
     * @param string $message
     * @return InvalidReferencesSet
     */
    public static function create($message)
    {
        return new self($message);
    }

    /**
     * @return InvalidReferencesSet
     */
    public static function forMoreThanOneParent()
    {
        return new self('Span can not have more than one parent, either one as child_of or either one as follows_from');
    }
}
