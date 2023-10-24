<?php
namespace DatadogApi\V1\Rest\DatadogRestService;

class DatadogRestServiceResourceFactory
{
    public function __invoke($services)
    {
        return new DatadogRestServiceResource();
    }
}
