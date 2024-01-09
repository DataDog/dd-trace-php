<?php

namespace DDTrace\Tests\Integrations\Symfony\V5_2;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class PathParamsTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_5_2/public/index.php';
    }

    public function testDynamicRouteWithOptionalsFilled()
    {
        $param01 = 'first_param';
        $param02 = 'second_param';
        $this->call(GetSpec::create('dynamic', "/dynamic_route/$param01/$param02"));
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($param01, $events[0]['param01']);
        $this->assertEquals($param02, $events[0]['param02']);
        $this->assertEquals('push_params', $events[0]['eventName']);
    }

    public function testDynamicRouteWithOptionalsNotFilled()
    {
        $param01 = 'first_param';
        $this->call(GetSpec::create('dynamic', "/dynamic_route/$param01"));
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($param01, $events[0]['param01']);
        $this->assertEmpty($events[0]['param02']);
        $this->assertEquals('push_params', $events[0]['eventName']);
    }


    public function testStaticRoute()
    {
        $this->call(GetSpec::create('static', "/simple"));
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(0, count($events));
    }
}
