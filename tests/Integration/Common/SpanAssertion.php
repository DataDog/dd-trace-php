<?php

namespace DDTrace\Tests\Integration\Common;


final class SpanAssertion
{
    const NOT_TESTED = '__not_tested__';

    private $operationName;
    private $hasError;
    /** @var string[] Tags the MUST match in both key and value */
    private $exactTags = SpanAssertion::NOT_TESTED;
    /** @var string[] Tags the MUST be present but with any value */
    private $existingTags = ['system.pid'];
    private $service = SpanAssertion::NOT_TESTED;
    private $type = SpanAssertion::NOT_TESTED;
    private $resource = SpanAssertion::NOT_TESTED;
    private $onlyCheckExistence = false;

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
     * @return SpanAssertion
     */
    public static function build($name, $service, $type, $resource, $exactTags = [], $parent = null, $error = false)
    {
        return SpanAssertion::forOperation($name, $error)
            ->service($service)
            ->resource($resource)
            ->type($type)
            ->withExactTags($exactTags)
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
     * @return $this
     */
    public function setError()
    {
        $this->hasError = true;
        return $this;
    }

    /**
     * @param array $tags
     * @return $this
     */
    public function withExactTags(array $tags)
    {
        $this->exactTags = $tags;
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
     * @return bool
     */
    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * @return string
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
                return $name !== 'system.pid';
            });
        }
        return $this->existingTags;
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
}
