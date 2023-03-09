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
     * @var array
     */
    private $body = [];

    /**
     * @param $name
     * @param string $method
     * @param string $path
     * @param string[] $headers An indexed array as expected by `curl_setopt`.
     * @param array $body An associative array as expected by 'curl_setopt' with the CURLOPT_POSTFIELDS option. Contains
     * the data that is being sent as part of the request.
     *
     */
    public function __construct($name, $method, $path, array $headers = [], array $body = [])
    {
        $this->name = $name;
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
        $this->body = $body;
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

    public function getBody()
    {
        return $this->body;
    }
}
