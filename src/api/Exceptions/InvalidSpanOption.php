<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Exceptions/InvalidSpanOption.php
 */

namespace DDTrace\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when passing an invalid option on Span creation
 */
final class InvalidSpanOption extends InvalidArgumentException
{
    /**
     * @return InvalidSpanOption
     */
    public static function forIncludingBothChildOfAndReferences()
    {
        return new self('Either "childOf" or "references" options are accepted but not both.');
    }

    /**
     * @param mixed $reference
     * @return InvalidSpanOption
     */
    public static function forInvalidReference($reference)
    {
        return new self(sprintf(
            'Invalid reference. Expected \DDTrace\Reference, got %s.',
            is_object($reference) ? get_class($reference) : gettype($reference)
        ));
    }

    /**
     * @return InvalidSpanOption
     */
    public static function forInvalidStartTime()
    {
        return new self(sprintf('Invalid start_time option. Expected int or float got string.'));
    }

    public static function forInvalidChildOf($childOfOption)
    {
        return new self(sprintf(
            'Invalid child_of option. Expected Span or SpanContext, got %s',
            is_object($childOfOption) ? get_class($childOfOption) : gettype($childOfOption)
        ));
    }

    /**
     * @param string $key
     * @return InvalidSpanOption
     */
    public static function forUnknownOption($key)
    {
        return new self(sprintf('Invalid option %s.', $key));
    }

    /**
     * @param mixed $tag
     * @return InvalidSpanOption
     */
    public static function forInvalidTag($tag)
    {
        return new self(sprintf('Invalid tag. Expected string, got %s', gettype($tag)));
    }

    /**
     * @param mixed $tagValue
     * @return InvalidSpanOption
     */
    public static function forInvalidTagValue($tagValue)
    {
        return new self(sprintf(
            'Invalid tag value. Expected scalar or object with __toString method, got %s',
            is_object($tagValue) ? get_class($tagValue) : gettype($tagValue)
        ));
    }

    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forInvalidTags($value)
    {
        return new self(sprintf(
            'Invalid tags value. Expected a associative array of tags, got %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }

    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forInvalidReferenceSet($value)
    {
        return new self(sprintf(
            'Invalid references set. Expected Reference or Reference[], got %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }

    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forFinishSpanOnClose($value)
    {
        return new self(sprintf(
            'Invalid type for finish_span_on_close. Expected bool, got %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }

    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forIgnoreActiveSpan($value)
    {
        return new self(sprintf(
            'Invalid type for ignore_active_span. Expected bool, got %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }
}
