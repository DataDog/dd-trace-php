<?php

require __DIR__ . '/../../../autoload.php';

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\ZendFramework\V1\TraceRequest;

class DDTrace_Ddtrace extends Zend_Application_Resource_ResourceAbstract
{
    const NAME = 'zf1';

    public function init()
    {
        if (!Integration::shouldLoad(self::NAME)) {
            return false;
        }
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new TraceRequest());

        $span = \DDTrace\root_span();
        $span->name = self::getOperationName();
        $span->service = \ddtrace_config_app_name(self::NAME);
    }

    /**
     * @return string
     */
    private static function getOperationName()
    {
        $contextName = 'cli' === PHP_SAPI ? 'command' : 'request';
        return self::NAME . '.' . $contextName;
    }
}
