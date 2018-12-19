<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/StartSpanOptions.php
 */

namespace DDTrace;

use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Exceptions\InvalidReferencesSet;
use DDTrace\Exceptions\InvalidSpanOption;

final class StartSpanOptions
{
    /**
     * @var Reference[]
     */
    private $references = [];

    /**
     * @var array
     */
    private $tags = [];

    /**
     * @var int|float|\DateTimeInterface
     */
    private $startTime;

    /**
     * Only used for spans that are actively managed by scope manager.
     *
     * @var bool
     */
    private $finishSpanOnClose = ScopeManagerInterface::DEFAULT_FINISH_SPAN_ON_CLOSE;

    /**
     * @var bool
     */
    private $ignoreActiveSpan = false;

    /**
     * @param array $options
     * @throws InvalidSpanOption when one of the options is invalid
     * @throws InvalidReferencesSet when there are inconsistencies about the references
     * @return StartSpanOptions
     */
    public static function create(array $options)
    {
        $spanOptions = new self();

        foreach ($options as $key => $value) {
            switch ($key) {
                case 'child_of':
                    if (!empty($spanOptions->references)) {
                        throw InvalidSpanOption::forIncludingBothChildOfAndReferences();
                    }

                    $spanOptions->references[] = self::buildChildOf($value);
                    break;

                case 'references':
                    if (!empty($spanOptions->references)) {
                        throw InvalidSpanOption::forIncludingBothChildOfAndReferences();
                    }

                    if ($value instanceof Reference) {
                        $spanOptions->references = [$value];
                    } elseif (is_array($value)) {
                        $spanOptions->references = self::buildReferences($value);
                    } else {
                        throw InvalidSpanOption::forInvalidReferenceSet($value);
                    }

                    break;

                case 'tags':
                    if (!is_array($value)) {
                        throw InvalidSpanOption::forInvalidTags($value);
                    }

                    foreach ($value as $tag => $tagValue) {
                        if ($tag !== (string) $tag) {
                            throw InvalidSpanOption::forInvalidTag($tag);
                        }

                        $spanOptions->tags[$tag] = $tagValue;
                    }
                    break;

                case 'start_time':
                    if (is_scalar($value) && !is_numeric($value)) {
                        throw InvalidSpanOption::forInvalidStartTime();
                    }

                    $spanOptions->startTime = $value;
                    break;

                case 'finish_span_on_close':
                    if (!is_bool($value)) {
                        throw InvalidSpanOption::forFinishSpanOnClose($value);
                    }

                    $spanOptions->finishSpanOnClose = $value;
                    break;

                case 'ignore_active_span':
                    if (!is_bool($value)) {
                        throw InvalidSpanOption::forIgnoreActiveSpan($value);
                    }

                    $spanOptions->ignoreActiveSpan = $value;
                    break;

                default:
                    throw InvalidSpanOption::forUnknownOption($key);
                    break;
            }
        }

        return $spanOptions;
    }

    /**
     * @param SpanInterface|SpanContextInterface $parent
     * @return StartSpanOptions
     */
    public function withParent($parent)
    {
        $newSpanOptions = new StartSpanOptions();
        $newSpanOptions->references[] = self::buildChildOf($parent);
        $newSpanOptions->tags = $this->tags;
        $newSpanOptions->startTime = $this->startTime;
        $newSpanOptions->finishSpanOnClose = $this->finishSpanOnClose;
        $newSpanOptions->ignoreActiveSpan = $this->ignoreActiveSpan;

        return $newSpanOptions;
    }

    /**
     * @return Reference[]
     */
    public function getReferences()
    {
        return $this->references;
    }

    /**
     * @return array
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @return int|float|\DateTime|null if returning float or int it should represent
     * the timestamp (including as many decimal places as you need)
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * @return bool
     */
    public function shouldFinishSpanOnClose()
    {
        return $this->finishSpanOnClose;
    }

    /**
     * @return bool
     */
    public function shouldIgnoreActiveSpan()
    {
        return $this->ignoreActiveSpan;
    }

    private static function buildChildOf($value)
    {
        if ($value instanceof SpanInterface) {
            return Reference::create(Reference::CHILD_OF, $value->getContext());
        }

        if ($value instanceof SpanContextInterface) {
            return Reference::create(Reference::CHILD_OF, $value);
        }

        throw InvalidSpanOption::forInvalidChildOf($value);
    }

    private static function buildReferences(array $referencesArray)
    {
        $references = [];

        foreach ($referencesArray as $reference) {
            if (!($reference instanceof Reference)) {
                throw InvalidSpanOption::forInvalidReference($reference);
            }

            $references[] = $reference;
        }

        return $references;
    }
}
