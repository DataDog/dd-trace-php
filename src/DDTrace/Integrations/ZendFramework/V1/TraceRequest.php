<?php

namespace DDTrace\Integrations\ZendFramework\V1;

use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;
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
        $integration = new ZendFrameworkIntegration();
        // Overwriting the default web integration
        $integration->addTraceAnalyticsIfEnabled($span);
        $controller = $request->getControllerName();
        $action = $request->getActionName();
        $route = Zend_Controller_Front::getInstance()->getRouter()->getCurrentRouteName();
        $span->meta['zf1.controller'] = $controller;
        $span->meta['zf1.action'] = $action;
        $span->meta['zf1.route_name'] = $route;
        $span->resource = $controller . '@' . $action . ' ' . $route;
        $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
        $span->meta[Tag::HTTP_URL] =
            $request->getScheme() . '://' .
            $request->getHttpHost() .
            $request->getRequestUri();
    }

    /**
     * @param Zend_Controller_Request_Abstract|Zend_Controller_Request_Http $request
     */
    public function postDispatch(Zend_Controller_Request_Abstract $request)
    {
        $span = \DDTrace\root_span();;
        if (null === $span) {
            return;
        }
        $span->meta[Tag::HTTP_STATUS_CODE] = $this->getResponse()->getHttpResponseCode();
    }
}
