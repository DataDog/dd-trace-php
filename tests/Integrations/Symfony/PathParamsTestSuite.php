<?php

namespace DDTrace\Tests\Integrations\Symfony;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

/**
 * @group appsec
 */
class PathParamsTestSuite extends AppsecTestCase
{
    public function testDynamicRouteWithOptionalsFilled()
    {
        $param01 = 'first_param';
        $param02 = 'second_param';
        $this->call(GetSpec::create('dynamic', "/dynamic_route/$param01/$param02"));
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals($param01, $events[0][0]["server.request.path_params"]['param01']);
        $this->assertEquals($param02, $events[0][0]["server.request.path_params"]['param02']);
    }

    public function testDynamicRouteWithOptionalsNotFilled()
    {
        $param01 = 'first_param';
        $this->call(GetSpec::create('dynamic', "/dynamic_route/$param01"));
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(1, count($events));
        $this->assertEquals($param01, $events[0][0]["server.request.path_params"]['param01']);
        $this->assertEmpty($events[0][0]["server.request.path_params"]['param02']);
    }

    public function testStaticRoute()
    {
        $this->call(GetSpec::create('static', "/simple"));
        $events = AppsecStatus::getInstance()->getEvents(['push_addresses'], ['server.request.path_params']);
        $this->assertEquals(0, count($events));
    }
}
