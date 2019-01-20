<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\Memcached\MemcachedIntegration;


/**
 * @deprecated: see -> DDTrace\Integrations\Memcached\MemcachedIntegration
 */
class Memcached extends MemcachedIntegration
{
    /**
     * A proxy to the new integration, temporarily left here for backward compatibility.
     */
    public static function load()
    {
        error_log('DEPRECATED: Class "DDTrace\Integrations\Memcached" will be removed soon, '
            . 'you should use the new integration "DDTrace\Integrations\Memcached\MemcachedIntegration"');
        return parent::load();
    }
}
