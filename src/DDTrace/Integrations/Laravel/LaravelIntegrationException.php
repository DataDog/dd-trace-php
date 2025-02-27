<?php

namespace DDTrace\Integrations\Laravel;

class LaravelIntegrationException extends \Exception
{
    public function render($request)
    {
        return '&nbsp;';
    }
}
