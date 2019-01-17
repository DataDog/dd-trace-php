<?php

namespace DDTrace\Integrations\ZendFramework\V1;

use DDTrace\GlobalTracer;
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
        $scope = GlobalTracer::get()->getRootScope();
        if (null === $scope) {
            return;
        }
        $span = $scope->getSpan();
        $span->setTag('zf1.controller', $request->getControllerName());
        $span->setTag('zf1.action', $request->getActionName());
        $span->setTag(
            'zf1.route_name',
            Zend_Controller_Front::getInstance()->getRouter()->getCurrentRouteName()
        );
        $span->setTag(Tag::HTTP_METHOD, $request->getMethod());
        $span->setTag(
            Tag::HTTP_URL,
            $request->getScheme() . '://' .
            $request->getHttpHost() .
            $request->getRequestUri()
        );
    }

    /**
     * @param Zend_Controller_Request_Abstract|Zend_Controller_Request_Http $request
     */
    public function postDispatch(Zend_Controller_Request_Abstract $request)
    {
        $scope = GlobalTracer::get()->getRootScope();
        if (null === $scope) {
            return;
        }
        $scope->getSpan()->setTag(Tag::HTTP_STATUS_CODE, $this->getResponse()->getHttpResponseCode());
    }
}
