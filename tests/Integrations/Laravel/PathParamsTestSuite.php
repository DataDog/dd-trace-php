<?php

namespace DDTrace\Tests\Integrations\Laravel;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class PathParamsTestSuite extends AppsecTestCase
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
        $this->assertEquals($param01, $events[0][0]["server.request.path_params"]['param01']);
        $this->assertEquals($param02, $events[0][0]["server.request.path_params"]['param02']);
    }

    public function testDynamicRouteWithOptionalParametersNotGiven()
    {
        $param01 = 'first_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01/static")
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertCount(1, $events[0][0]["server.request.path_params"]);
        $this->assertEquals($param01, $events[0][0]["server.request.path_params"]['param01']);
    }

    public function testStaticRouteDoesNotGenerateEvent()
    {
        $this->call(
            GetSpec::create('Call to static route', "/simple")
        );
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(0, count($events));
    }
}
