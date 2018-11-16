<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\PDO\PDOIntegration;


/**
 * @deprecated: see -> DDTrace\Integrations\PDO\PDOIntegration
 */
class PDO extends PDOIntegration
{
    /**
     * A proxy to the new integration, temporarily left here for backward compatibility.
     */
    public static function load()
    {
        error_log('DEPRECATED: Class "DDTrace\Integrations\PDO" will be removed soon, '
            . 'you should use the new integration "DDTrace\Integrations\PDO\PDOIntegration"');
        return parent::load();
    }
}
