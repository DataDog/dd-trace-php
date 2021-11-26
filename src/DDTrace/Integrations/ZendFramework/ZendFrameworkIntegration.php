<?php

namespace DDTrace\Integrations\ZendFramework;

use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Util\Runtime;
use Zend_Controller_Front;

/**
 * Zend framework integration loader.
 */
class ZendFrameworkIntegration extends Integration
{
    const NAME = 'zendframework';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * Loads the zend framework integration.
     *
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        // Some frameworks, e.g. Yii registers autoloaders that fails with non-psr4 classes. For this reason the
        // Zend framework integration is not compatible with some of them
        if (Runtime::isAutoloaderRegistered('YiiBase', 'autoload')) {
            return self::NOT_AVAILABLE;
        }

        $integration = $this;
        // For backward compatibility with the legacy API we are not using the integration
        // name 'zendframework', we are instead using the 'zf1' prefix.
        $appName = \ddtrace_config_app_name('zf1');

        \DDTrace\trace_method(
            'Zend_Controller_Plugin_Broker',
            'preDispatch',
            function (SpanData $spanData, $args) use ($integration, $appName) {
                $rootSpan = \DDTrace\root_span();
                if (null === $rootSpan) {
                    return false;
                }

                // We are enclosing this into a try-catch because we always have to return false,
                // even at the cost of not setting specific metadata.
                try {
                    /** @var Zend_Controller_Request_Abstract $request */
                    list($request) = $args;
                    $integration->addTraceAnalyticsIfEnabled($rootSpan);
                    $rootSpan->name = $integration->getOperationName();
                    $rootSpan->service = $appName;
                    $controller = $request->getControllerName();
                    $action = $request->getActionName();
                    $route = Zend_Controller_Front::getInstance()->getRouter()->getCurrentRouteName();
                    $rootSpan->meta['zf1.controller'] = $controller;
                    $rootSpan->meta['zf1.action'] = $action;
                    $rootSpan->meta['zf1.route_name'] = $route;
                    $rootSpan->resource = $controller . '@' . $action . ' ' . $route;
                    $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                    $rootSpan->meta[Tag::HTTP_URL] =
                        $request->getScheme() . '://' .
                        $request->getHttpHost() .
                        $request->getRequestUri();
                } catch (\Exception $e) {
                }

                return false;
            }
        );

        \DDTrace\trace_method('Zend_Controller_Plugin_Broker', 'postDispatch', function () {
            $rootSpan = \DDTrace\root_span();
            if (null === $rootSpan) {
                return false;
            }

            // We are enclosing this into a try-catch because we always have to return false,
            // even at the cost of not setting specific metadata.
            try {
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $this->getResponse()->getHttpResponseCode();
            } catch (\Exception $e) {
            }

            return false;
        });

        return Integration::LOADED;
    }

    /**
     * @return string
     */
    public static function getOperationName()
    {
        $contextName = 'cli' === PHP_SAPI ? 'command' : 'request';
        // For backward compatibility with the legacy API we are not using the integration
        // name 'zendframework', we are instead using the 'zf1' prefix.
        return 'zf1' . '.' . $contextName;
    }
}
