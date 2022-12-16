<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tag;
use DDTrace\Util\Versions;

final class SpanAssertion
{
    const NOT_TESTED = '__not_tested__';

    private static $integrationName;

    private $operationName;
    private $hasError;
    /** @var string[] Tags the MUST match in both key and value */
    private $exactTags = SpanAssertion::NOT_TESTED;
    /** @var string[] Tags the MUST be present but with any value */
    private $existingTags = ['process_id'];
    /** @var string[] Ignore any tags that match these regexp patterns */
    private $skipTagPatterns = [];
    /** @var array Exact metrics set on the span */
    private $exactMetrics = SpanAssertion::NOT_TESTED;
    private $service = SpanAssertion::NOT_TESTED;
    private $type = SpanAssertion::NOT_TESTED;
    private $resource = SpanAssertion::NOT_TESTED;
    private $onlyCheckExistence = false;
    private $isTraceAnalyticsCandidate = false;
    private $testTime = true;
    /** @var SpanAssertion[] */
    private $children = [];

    private $toBeSkipped = false;

    private $statusCode = SpanAssertion::NOT_TESTED;

    /**
     * @param string $name
     * @param bool $error
     * @param bool $onlyCheckExistance
     */
    private function __construct($name, $error, $onlyCheckExistance)
    {
        $this->operationName = $name;
        $this->hasError = $error;
        $this->onlyCheckExistence = $onlyCheckExistance;
        if (SpanAssertion::$integrationName != SpanAssertion::NOT_TESTED) {
            $this->exactTags = [
                Tag::COMPONENT => SpanAssertion::$integrationName,
            ];
        }
    }

    public static function setIntegrationName($integrationName)
    {
        SpanAssertion::$integrationName = $integrationName;
    }

    /**
     * @param string $name
     * @param null $resource
     * @param bool $error
     * @return SpanAssertion
     */
    public static function exists($name, $resource = null, $error = false)
    {
        return SpanAssertion::forOperation($name, $error, true)
            ->resource($resource);
    }

    /**
     * @param string $name
     * @param string $service
     * @param string $type
     * @param string $resource
     * @param array $exactTags
     * @param null $parent
     * @param bool $error
     * @param array|string $extactMetrics
     * @return SpanAssertion
     */
    public static function build(
        $name,
        $service,
        $type,
        $resource,
        $exactTags = [],
        $parent = null,
        $error = false,
        $extactMetrics = SpanAssertion::NOT_TESTED
    ) {
        return SpanAssertion::forOperation($name, $error)
            ->service($service)
            ->resource($resource)
            ->type($type)
            ->withExactTags($exactTags)
            ->withExactMetrics($extactMetrics)
        ;
    }

    /**
     * @param string $name
     * @param bool $error
     * @param bool $onlyCheckExistence
     * @return SpanAssertion
     */
    public static function forOperation($name, $error = false, $onlyCheckExistence = false)
    {
        return new SpanAssertion($name, $error, $onlyCheckExistence);
    }

    /**
     * @param string|null $errorType The expected error.type
     * @param string|null $errorMessage The expected error.message
     * @param bool $exceptionThrown If we would expect error.stack
     * @return $this
     */
    public function setError($errorType = null, $errorMessage = null, $exceptionThrown = false)
    {
        $this->hasError = true;
        if (!is_array($this->exactTags)) {
            $this->exactTags = [];
        }
        if (isset($this->exactTags[Tag::ERROR_TYPE])) {
            return $this;
        }
        if (null !== $errorType) {
            $this->exactTags[Tag::ERROR_TYPE] = $errorType;
        } else {
            $this->existingTags[] = Tag::ERROR_TYPE;
        }
        if (null !== $errorMessage) {
            // commonly contains file/line info, use global format
            // we do not want to test the specific formatting of exceptions here
            $this->exactTags[Tag::ERROR_MSG] = "%S$errorMessage%S";
        }
        if ($exceptionThrown) {
            $this->existingTags[] = Tag::ERROR_STACK;
        }
        return $this;
    }

    /**
     * @param SpanAssertion|SpanAssertion[] $children
     * @return $this
     */
    public function withChildren($children)
    {
        $toBeAdded = is_array($children) ? $children : [$children];
        $this->children = array_merge(
            $this->children,
            array_values(array_filter($toBeAdded, function (SpanAssertion $assertion) {
                return !$assertion->isToBeSkipped();
            }))
        );
        return $this;
    }

    /**
     * @param array|string $tags
     * @return $this
     */
    public function withExactTags($tags)
    {
        if (is_array($this->exactTags) && is_array($tags)) {
            $this->exactTags = array_merge($this->exactTags, $tags);
        } else {
            $this->exactTags = $tags;
        }
        return $this;
    }

    /**
     * @param array|string $metrics
     * @return $this
     */
    public function withExactMetrics($metrics)
    {
        $this->exactMetrics = $metrics;
        return $this;
    }

    /**
     * @param array $tagNames
     * @param bool $merge If true, the provided tags are nmerged with the default ones
     * @return $this
     */
    public function withExistingTagsNames(array $tagNames, $merge = true)
    {
        $this->existingTags = $merge ? array_merge($tagNames, $this->existingTags) : $tagNames;
        return $this;
    }

    /**
     * @param string $pattern regular expression
     * @return $this
     */
    public function skipTagsLike($pattern)
    {
        $this->skipTagPatterns[] = $pattern;
        return $this;
    }

    public function getSkippedTagPatterns()
    {
        return $this->skipTagPatterns;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function service($name)
    {
        $this->service = $name;
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function type($name)
    {
        $this->type = $name;
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function resource($name)
    {
        $this->resource = $name;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getOperationName()
    {
        return $this->operationName;
    }

    /**
     * @return SpanAssertion[]
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @return bool
     */
    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * @return string[]
     */
    public function getExactTags()
    {
        return $this->exactTags;
    }

    /**
     * @param bool $isChildSpan
     * @return string[]
     */
    public function getExistingTagNames($isChildSpan = false)
    {
        if ($isChildSpan) {
            return array_filter($this->existingTags, function ($name) {
                return $name !== 'process_id';
            });
        }
        return $this->existingTags;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return bool
     */
    public function isOnlyCheckExistence()
    {
        return $this->onlyCheckExistence;
    }

    /**
     * @return self
     */
    public function setTraceAnalyticsCandidate()
    {
        $this->isTraceAnalyticsCandidate = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function isTraceAnalyticsCandidate()
    {
        return $this->isTraceAnalyticsCandidate;
    }

    /**
     * @return array
     */
    public function getExactMetrics()
    {
        return $this->exactMetrics;
    }

    /**
     * @return bool
     */
    public function getTestTime()
    {
        return $this->testTime;
    }

    public function __toString()
    {
        return sprintf(
            "{name:'%s' resource:'%s'}",
            $this->getOperationName(),
            $this->getResource()
        );
    }

    /**
     * @param $condition
     * @return $this
     */
    public function skipIf($condition)
    {
        $this->toBeSkipped = (bool)$condition;
        return $this;
    }

    /**
     * @param $condition
     * @return $this
     */
    public function onlyIf($condition)
    {
        return $this->skipIf(!$condition);
    }

    /**
     * @return bool
     */
    public function isToBeSkipped()
    {
        return $this->toBeSkipped;
    }

    /**
     * Executes a callback only if the php version does not match the provided one.
     * Version can be provided in the form: '5' -> all 5, '7.1' -> all 7.1.*, '7.1.2' -> only 7.1.2
     * The callback will receive only one argument, which is the current assertion itself.
     *
     * @param string $version
     * @param Callable $callback
     * @return $this
     */
    public function ifPhpVersionNotMatch($version, $callback)
    {
        if (Versions::phpVersionMatches($version)) {
            return $this;
        }

        $callback($this);
        return $this;
    }
}
