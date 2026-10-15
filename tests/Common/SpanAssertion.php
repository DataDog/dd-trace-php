<?php

namespace DDTrace\Tests\Common;

use DDTrace\Tag;
use DDTrace\Util\Versions;
use Exception;

final class SpanAssertion
{
    const NOT_TESTED = '__not_tested__';

    private $operationName;
    private $hasError;
    /** @var array Attributes that MUST match in both key and (typed) value */
    private $exactAttributes = SpanAssertion::NOT_TESTED;
    /** @var string[] Attributes that MUST be present but with any value */
    private $existingAttributes = ['process_id'];
    /** @var string[] Ignore any attributes that match these regexp patterns */
    private $skipAttributePatterns = [];
    /** @var array Fields of the span's chunk (priority, sampling_mechanism, origin) */
    private $chunkFields = [];
    /** @var bool Whether the span MUST have span links */
    private $spanLinks = false;
    private $service = SpanAssertion::NOT_TESTED;
    private $type = SpanAssertion::NOT_TESTED;
    private $resource = SpanAssertion::NOT_TESTED;
    private $onlyCheckExistence = false;
    private $testTime = true;
    /** @var SpanAssertion[] */
    private $children = [];

    private $toBeSkipped = false;

    private $statusCode = SpanAssertion::NOT_TESTED;

    /**
     * @param string $name
     * @param bool|string $error
     * @param bool $onlyCheckExistance
     */
    private function __construct($name, $error, $onlyCheckExistance)
    {
        $this->operationName = $name;
        $this->hasError = $error;
        $this->onlyCheckExistence = $onlyCheckExistance;
    }

    /**
     * @param string $name
     * @param null|string $resource
     * @param bool|string $error
     * @param null|string $service
     * @return SpanAssertion
     */
    public static function exists(
        $name,
        $resource = SpanAssertion::NOT_TESTED,
        $error = SpanAssertion::NOT_TESTED,
        $service = SpanAssertion::NOT_TESTED
    ){
        if ($error === null) {
            $error = false;
        }

        if ($resource === null) {
            $resource = SpanAssertion::NOT_TESTED;
        }

        if ($error === null) { // false is a valid value, we don't convert it
            $error = SpanAssertion::NOT_TESTED;
        }

        return SpanAssertion::forOperation($name, $error, true)
            ->resource($resource)
            ->service($service);
    }

    /**
     * @param string $name
     * @param string $service
     * @param string $type
     * @param string $resource
     * @param array $exactAttributes
     * @param null $parent
     * @param bool $error
     * @return SpanAssertion
     */
    public static function build(
        $name,
        $service,
        $type,
        $resource,
        $exactAttributes = [],
        $parent = null,
        $error = false
    ) {
        return SpanAssertion::forOperation($name, $error)
            ->service($service)
            ->resource($resource)
            ->type($type)
            ->withExactAttributes($exactAttributes)
        ;
    }

    /**
     * @param string $name
     * @param bool|string $error
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
        if (!is_array($this->exactAttributes)) {
            $this->exactAttributes = [];
        }
        if (isset($this->exactAttributes[Tag::ERROR_TYPE])) {
            return $this;
        }
        if (null !== $errorType) {
            $this->exactAttributes[Tag::ERROR_TYPE] = $errorType;
        } else {
            $this->existingAttributes[] = Tag::ERROR_TYPE;
        }
        if (null !== $errorMessage) {
            // commonly contains file/line info, use global format
            // we do not want to test the specific formatting of exceptions here
            $this->exactAttributes[Tag::ERROR_MSG] = "%S$errorMessage%S";
        }
        if ($exceptionThrown) {
            $this->existingAttributes[] = Tag::ERROR_STACK;
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
     * The span's attributes (and env, version, component and span.kind) must be exactly these, compared
     * with their type; string values are formats (see assertStringMatchesFormat). If multiple arrays are
     * provided, they will be merged with the right-most ones taking precedence over the left-most ones.
     *
     * @param array... $attributes
     * @return $this
     */
    public function withExactAttributes(...$attributes)
    {
        if ($attributes[0] === self::NOT_TESTED) {
            $this->exactAttributes = self::NOT_TESTED;
            return $this;
        }

        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                throw new Exception(
                    'All arguments provided to ::withExactAttributes MUST be arrays: ' . \var_export($attribute, true)
                );
            }
        }

        $this->exactAttributes = array_merge(
            \is_array($this->exactAttributes) ? $this->exactAttributes : [],
            ...$attributes
        );

        return $this;
    }

    /**
     * @param array $names
     * @param bool $merge If true, the provided names are merged with the default ones
     * @return $this
     */
    public function withExistingAttributeNames(array $names, $merge = true)
    {
        $this->existingAttributes = $merge ? array_merge($names, $this->existingAttributes) : $names;
        return $this;
    }

    /**
     * @param string $pattern regular expression
     * @return $this
     */
    public function skipAttributesLike($pattern)
    {
        $this->skipAttributePatterns[] = $pattern;
        return $this;
    }

    public function getSkippedAttributePatterns()
    {
        return $this->skipAttributePatterns;
    }

    /**
     * The span MUST have span links.
     *
     * @return $this
     */
    public function withSpanLinks()
    {
        $this->spanLinks = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasSpanLinks()
    {
        return $this->spanLinks;
    }

    /**
     * @param int $priority The chunk's sampling priority
     * @return $this
     */
    public function samplingPriority($priority)
    {
        $this->chunkFields['priority'] = $priority;
        return $this;
    }

    /**
     * @param int $mechanism The chunk's sampling mechanism (the _dd.p.dm decision maker)
     * @return $this
     */
    public function samplingMechanism($mechanism)
    {
        $this->chunkFields['sampling_mechanism'] = $mechanism;
        return $this;
    }

    /**
     * @param string $origin The chunk's origin
     * @return $this
     */
    public function origin($origin)
    {
        $this->chunkFields['origin'] = $origin;
        return $this;
    }

    /**
     * @return array
     */
    public function getExactChunkFields()
    {
        return $this->chunkFields;
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
     * @return array|string
     */
    public function getExactAttributes()
    {
        return $this->exactAttributes;
    }

    /**
     * @param bool $isChildSpan
     * @return string[]
     */
    public function getExistingAttributeNames($isChildSpan = false)
    {
        if ($isChildSpan) {
            return array_filter($this->existingAttributes, function ($name) {
                return $name !== 'process_id';
            });
        }
        return $this->existingAttributes;
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
