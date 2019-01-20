<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\Mysqli\MysqliIntegration;


/**
 * @deprecated: see -> DDTrace\Integrations\Mysqli\MysqliIntegration
 */
class Mysqli extends MysqliIntegration
{
    /**
     * A proxy to the new integration, temporarily left here for backward compatibility.
     */
    public static function load()
    {
        error_log('DEPRECATED: Class "DDTrace\Integrations\Mysqli" will be removed soon, '
            . 'you should use the new integration "DDTrace\Integrations\Mysqli\MysqliIntegration"');
        return parent::load();
    }
}
