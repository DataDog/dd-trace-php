<?php

namespace DDTrace\Tests\Frameworks\Util\Request;

class RequestSpec
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
     * @var string[]
     */
    private $headers = [];

    /**
     * @param $name
     * @param string $method
     * @param string $path
     * @param array $headers
     */
    public function __construct($name, $method, $path, array $headers = [])
    {
        $this->name = $name;
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
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

    /**
     * @return string[]
     */
    public function getHeaders()
    {
        return $this->headers;
    }
}
