<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Reference.php
 */

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
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
     * @var SpanContextInterface
     */
    private $context;

    /**
     * @param string $type
     * @param SpanContextInterface $context
     */
    private function __construct($type, SpanContextInterface $context)
    {
        $this->type = $type;
        $this->context = $context;
    }

    /**
     * @param SpanContextInterface|SpanInterface $context
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
     * @return SpanContextInterface
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
        if ($context instanceof SpanContextInterface) {
            return $context;
        }

        if ($context instanceof SpanInterface) {
            return $context->getContext();
        }

        throw InvalidReferenceArgument::forInvalidContext($context);
    }
}
