<?php

require __DIR__ . '/../../../autoload.php';

use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\ZendFramework\V1\TraceRequest;
use DDTrace\Tag;
use DDTrace\Tracer;

class DDTrace_Ddtrace extends Zend_Application_Resource_ResourceAbstract
{
    const NAME = 'zf1';

    /**
     * @var Tracer
     */
    private $tracer;

    public function init()
    {
        if (!Integration::shouldLoad(self::NAME)) {
            return false;
        }
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new TraceRequest());

        $tracer = GlobalTracer::get();
        $span = $tracer->getRootScope()->getSpan();
        $span->overwriteOperationName(self::getOperationName());
        $span->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name(self::NAME));

        return $this->tracer;
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
