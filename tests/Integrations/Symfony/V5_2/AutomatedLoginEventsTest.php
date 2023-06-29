<?php

namespace DDTrace\Tests\Integrations\Symfony\V5_2;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use datadog\appsec\AppsecStatus;

class AutomatedLoginEventsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_5_2/public/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from user where email LIKE 'test-user%'");
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

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        //Password is password
        $this->connection()->exec('insert into user (roles, email, password) VALUES ("{}", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');

         $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents();
         $this->assertEquals(1, count($events));
         $this->assertEquals($email, $events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
         $this->assertTrue($events[0]['automated']);
         $this->assertEquals('track_user_login_success_event', $events[0]['eventName']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';
         $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents();
         $this->assertEquals(1, count($events));
         $this->assertEmpty($events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
         $this->assertTrue($events[0]['automated']);
         $this->assertEquals('track_user_login_failure_event', $events[0]['eventName']);
    }
}
