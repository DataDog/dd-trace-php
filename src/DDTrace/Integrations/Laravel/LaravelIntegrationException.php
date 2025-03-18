<?php

namespace DDTrace\Integrations\Laravel;

class LaravelIntegrationException extends \Exception
{
    public function render($request)
    {
        return "alex change me for the space";
    }
}
