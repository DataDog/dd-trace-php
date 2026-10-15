<?php

namespace DDTrace\Integrations\ZendFramework\V1;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;
use DDTrace\SpanKind;
use DDTrace\Tag;
use Zend_Controller_Front;
use Zend_Controller_Plugin_Abstract;
use Zend_Controller_Request_Abstract;
use Zend_Controller_Request_Http;

class TraceRequest extends Zend_Controller_Plugin_Abstract
{
    /**
     * @param Zend_Controller_Request_Abstract|Zend_Controller_Request_Http $request
     */
    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
        $span = \DDTrace\root_span();
        if (null === $span) {
            return;
        }
        // Overwriting the default web integration
        $controller = $request->getControllerName();
        $action = $request->getActionName();
        $route = Zend_Controller_Front::getInstance()->getRouter()->getCurrentRouteName();
        $span->attributes['zf1.controller'] = $controller;
        $span->attributes['zf1.action'] = $action;
        $span->attributes['zf1.route_name'] = $route;
        if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
            $span->resource = $controller . '@' . $action . ' ' . $route;
        }
        $span->attributes[Tag::HTTP_METHOD] = $request->getMethod();
        $span->spanKind = SpanKind::SERVER;
        $span->component = ZendFrameworkIntegration::NAME;

        if (!Integration::hasTag($span, Tag::HTTP_URL)) {
            $span->attributes[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize(
                $request->getScheme() . '://' .
                $request->getHttpHost() .
                $request->getRequestUri()
            );
        }
    }

    /**
     * @param Zend_Controller_Request_Abstract|Zend_Controller_Request_Http $request
     */
    public function postDispatch(Zend_Controller_Request_Abstract $request)
    {
        $span = \DDTrace\root_span();
        if (null === $span) {
            return;
        }
        $span->attributes[Tag::HTTP_STATUS_CODE] = (string)$this->getResponse()->getHttpResponseCode();
    }
}
