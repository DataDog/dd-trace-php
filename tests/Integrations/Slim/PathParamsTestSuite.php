<?php

namespace DDTrace\Tests\Integrations\Slim;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

abstract class PathParamsTestSuite extends AppsecTestCase
{
    public function testDynamicRouteWithAllParametersGiven()
    {
        $param01 = 'first_param';
        $param02 = 'second_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01/static/$param02")
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals(
            ['param01' => $param01, 'param02' => $param02],
            $events[0][0]['server.request.path_params']
        );
    }

    public function testDynamicRouteWithOptionalParametersNotGiven()
    {
        $param01 = 'first_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01")
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertSame(['param01' => $param01], $events[0][0]['server.request.path_params']);
    }

    public function testPathParamsAreUrlDecoded()
    {
        $this->call(
            GetSpec::create('Call to dynamic path with an encoded value', '/dynamic-path/a%20b')
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertSame(['param01' => 'a b'], $events[0][0]['server.request.path_params']);
    }

    public function testStaticRouteDoesNotGenerateEvent()
    {
        $this->call(
            GetSpec::create('Call to static route', '/simple')
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(0, count($events));
    }

    public function testUnmatchedRouteDoesNotGenerateEvent()
    {
        $this->call(
            GetSpec::create('Call to a route that does not exist', '/this/route/does/not/exist/at/all')
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(0, count($events));
    }
}
