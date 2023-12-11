<?php

namespace DDTrace\Tests\Integrations\Symfony\V4_4;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class PathParamsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_4_4/public/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        AppsecStatus::getInstance()->setDefaults();
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        AppsecStatus::getInstance()->init();
    }

    public static function ddTearDownAfterClass()
    {
        AppsecStatus::getInstance()->destroy();
        parent::ddTearDownAfterClass();
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
