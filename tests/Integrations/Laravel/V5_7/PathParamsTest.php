<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

/**
 * @group appsec
 */
class PathParamsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        AppsecStatus::getInstance()->init();
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        AppsecStatus::getInstance()->setDefaults();
    }

    public static function ddTearDownAfterClass()
    {
        AppsecStatus::getInstance()->destroy();
        parent::ddTearDownAfterClass();
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
        $this->assertEquals($param01, $events[0]['param01']);
        $this->assertEquals($param02, $events[0]['param02']);
        $this->assertEquals('ddappsec_push_address', $events[0]['eventName']);
    }

    public function testDynamicRouteWithOptionalParametersNotGiven()
    {
        $param01 = 'first_param';
        $this->call(
            GetSpec::create('Call to dynamic route', "/dynamic_route/$param01/static")
        );
        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertCount(2, $events[0]); //One for the event and one for the given parameter. Optional not present
        $this->assertEquals($param01, $events[0]['param01']);
        $this->assertEquals('ddappsec_push_address', $events[0]['eventName']);
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
