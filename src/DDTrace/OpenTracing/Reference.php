<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Reference.php
 */

namespace DDTrace\OpenTracing;

use DDTrace\Contracts\Span;
use DDTrace\Contracts\SpanContext;
use DDTrace\Exceptions\InvalidReferenceArgument;

final class Reference
{
    /**
     * A Span may be the ChildOf a parent Span. In a ChildOf reference,
     * the parent Span depends on the child Span in some capacity.
     */
    const CHILD_OF = 'child_of';

    /**
     * Some parent Spans do not depend in any way on the result of their
     * child Spans. In these cases, we say merely that the child Span
     * FollowsFrom the parent Span in a causal sense.
     */
    const FOLLOWS_FROM = 'follows_from';

    /**
     * @var string
     */
    private $type;

    /**
     * @var SpanContext
     */
    private $context;

    /**
     * @param string $type
     * @param SpanContext $context
     */
    private function __construct($type, SpanContext $context)
    {
        $this->type = $type;
        $this->context = $context;
    }

    /**
     * @param SpanContext|Span $context
     * @param string $type
     * @throws InvalidReferenceArgument on empty type
     * @return Reference when context is invalid
     */
    public static function create($type, $context)
    {
        if (empty($type)) {
            throw InvalidReferenceArgument::forEmptyType();
        }

        return new self($type, self::extractContext($context));
    }

    /**
     * @return SpanContext
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Checks whether a Reference is of one type.
     *
     * @param string $type the type for the reference
     * @return bool
     */
    public function isType($type)
    {
        return $this->type === $type;
    }

    private static function extractContext($context)
    {
        if ($context instanceof SpanContext) {
            return $context;
        }

        if ($context instanceof Span) {
            return $context->getContext();
        }

        throw InvalidReferenceArgument::forInvalidContext($context);
    }
}
