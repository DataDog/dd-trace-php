<?php

namespace DDTrace\Integrations\Guzzle\v6;

use DDTrace\Tags;
use DDTrace\Types;
use GuzzleHttp\Client;
use OpenTracing\GlobalTracer;

class GuzzleTracer
{
    private $client;
    private $command;
    private $args;

    private $scope;
    private $span;

    public function __construct(Client $client, $command, array $args)
    {
        $this->client = $client;
        $this->command = $command;
        $this->args = $args;

        $this->scope = GlobalTracer::get()->startActiveSpan("GuzzleHttp\Client.$command");
        $this->span = $this->scope->getSpan();
        $this->span->setTag(Tags\SPAN_TYPE, Types\GUZZLE);
        $this->span->setTag(Tags\SERVICE_NAME, 'guzzle');
        $this->span->setTag('guzzle.command', $command);
        $this->span->setResource($command);
    }

    public function setTag($key, $value)
    {
        $this->span->setTag($key, $value);
    }

    public function trace()
    {
        try {
            return $this->client->{$this->command}(...$this->args);
        } catch (\Exception $e) {
            $this->span->setError($e);
            throw $e;
        } finally {
            $this->scope->close();
        }
    }
}
