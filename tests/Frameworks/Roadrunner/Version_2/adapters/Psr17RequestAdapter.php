<?php
namespace Adapters;

class Psr17RequestAdapter implements \Psr\Http\Message\ServerRequestInterface
{
    private \Spiral\RoadRunner\Http\Request $req;

    public function __construct(\Spiral\RoadRunner\Http\Request $req)
    {
        $this->req = $req;
    }

    public function getProtocolVersion()
    {
        return $this->req->protocol;
    }

    public function withProtocolVersion(string $version)
    {
    }

    public function getHeaders()
    {
        return $this->req->headers;
    }

    public function hasHeader(string $name)
    {
        return isset($this->req->headers[$name]);
    }

    public function getHeader(string $name)
    {
        return $this->req->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name)
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value)
    {
    }

    public function withAddedHeader(string $name, $value)
    {
    }

    public function withoutHeader(string $name)
    {
    }

    public function getBody()
    {
        return Stream::create($this->req->body);
    }

    public function withBody(\Psr\Http\Message\StreamInterface $body)
    {
    }

    public function getRequestTarget()
    {
        return "/";
    }

    public function withRequestTarget(string $requestTarget)
    {
    }

    public function getMethod()
    {
        return $this->req->method;
    }

    public function withMethod(string $method)
    {
    }

    public function getUri()
    {
        return new Uri($this->req->uri);
    }

    public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false)
    {
    }

    public function getServerParams()
    {
        return array();
    }

    public function getCookieParams()
    {
        return $this->req->cookies;
    }

    public function withCookieParams(array $cookies)
    {
    }

    public function getQueryParams()
    {
        return $this->req->query;
    }

    public function withQueryParams(array $query)
    {
    }

    public function getUploadedFiles()
    {
        return array();
    }

    public function withUploadedFiles(array $uploadedFiles)
    {
    }

    public function getParsedBody()
    {
        return $this->req->getParsedBody();
    }

    public function withParsedBody($data)
    {
    }

    public function getAttributes()
    {
        return array();
    }

    public function getAttribute(string $name, $default = null)
    {
        return null;
    }

    public function withAttribute(string $name, $value)
    {
    }

    public function withoutAttribute(string $name)
    {
    }
}
