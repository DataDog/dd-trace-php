<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

abstract class RequestSpec
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $method;

    /**
     * @var string
     */
    private $path;

    /**
     * @var int
     */
    private $statusCode = 200;

    /**
     * @param string $name
     * @param string $path
     */
    // abstract public static function create($name, $path);

    /**
     * @param $name
     * @param string $method
     * @param string $path
     */
    protected function __construct($name, $method, $path)
    {
        $this->name = $name;
        $this->method = $method;
        $this->path = $path;
    }

    /**
     * @param int $statusCode
     * @return $this
     */
    public function expectStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
        return $this;
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
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }
}
