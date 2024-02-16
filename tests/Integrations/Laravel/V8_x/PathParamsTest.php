<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

/**
 * @group appsec
 */
class PathParamsTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    public function testDynamicRouteWithAllParametersGiven()
    {
        $param01 = 'first_param';
        $param02 = 'second_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01/static/$param02")
        );
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($param01, $events[0]["server.request.path_params"]['param01']);
        $this->assertEquals($param02, $events[0]["server.request.path_params"]['param02']);
        $this->assertEquals('push_address', $events[0]['eventName']);
    }

    public function testDynamicRouteWithOptionalParametersNotGiven()
    {
        $param01 = 'first_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01/static")
        );
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertCount(1, $events[0]["server.request.path_params"]);
        $this->assertEquals($param01, $events[0]["server.request.path_params"]['param01']);
        $this->assertEquals('push_address', $events[0]['eventName']);
    }

    public function testStaticRouteDoesNotGenerateEvent()
    {
        $this->call(
            GetSpec::create('Call to static route', "/simple")
        );
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(0, count($events));
    }
}
