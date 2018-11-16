<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\Symfony\V4\SymfonyBundle as SymfonyV4Bundle;


/**
 * @deprecated: see -> DDTrace\Integrations\Symfony\V4\SymfonyBundle
 */
class SymfonyBundle extends SymfonyV4Bundle
{
    /**
     * A proxy to the new integration, temporarily left here for backward compatibility.
     */
    public function boot()
    {
        error_log('DEPRECATED: Class "DDTrace\Integrations\SymfonyBundle" will be removed soon, '
            . 'you should use the new integration "DDTrace\Integrations\Symfony\V4\SymfonyBundle"');
        return parent::boot();
    }
}
