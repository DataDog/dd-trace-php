<?php

namespace DDTrace\Tests\Integrations\WordPress\V4_8;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use datadog\appsec\AppsecStatus;

class AutomatedLoginEventsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_4_8/index.php';
    }

    protected function connection()
    {
        return new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec(file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_4_8/wp_2019-10-01.sql'));
        $this->connection()->exec("DELETE from users where email LIKE 'test-user%'");
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
        $id = 123;
        $name = 'some name';
        //Password is test
        $this->connection()->exec(
            'INSERT INTO wp_users VALUES ('.$id.',"test","$P$BDzpK1XXL9P2cYWggPMUbN87GQSiI80","test","'.$email.'","","2020-10-22 16:31:15","",0,"'.$name.'")'
        );

        $spec = PostSpec::create('request', '/wp-login.php', [
            'Content-Type: application/x-www-form-urlencoded'
        ], "log=$email&pwd=$password&wp-submit=Log In");

        $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIESESSION => true ]);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($id, $events[0]['userId']);
        $this->assertEquals($email, $events[0]['metadata']['email']);
        $this->assertEquals($name, $events[0]['metadata']['name']);
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_success_event', $events[0]['eventName']);
    }

    public function testUserLoginFailureEventWhenUserDoesNotExists()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';
        $spec = PostSpec::create('request', '/wp-login.php', [
                    'Content-Type: application/x-www-form-urlencoded'
                ], "log=$email&pwd=$password&wp-submit=Log In");

        $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIESESSION => true ]);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($email, $events[0]['userId']);
        $this->assertFalse($events[0]['exists']);
        $this->assertEmpty($events[0]['metadata']);
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_failure_event', $events[0]['eventName']);
    }

    public function testUserLoginFailureEventWhenUserDoesExists()
    {
        $email = 'test-user-2@email.com';
        $password = 'test';
        $id = 333;
        $name = 'some name';
        //Password is test
        $this->connection()->exec(
            'INSERT INTO wp_users VALUES ('.$id.',"test","$P$BDzpK1XXL9P2cYWggPMUbN87GQSiI80","test","'.$email.'","","2020-10-22 16:31:15","",0,"'.$name.'")'
        );

        $spec = PostSpec::create('request', '/wp-login.php', [
            'Content-Type: application/x-www-form-urlencoded'
        ], "log=$email&pwd=invalid&wp-submit=Log In");

        $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIESESSION => true ]);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($email, $events[0]['userId']);
        $this->assertTrue($events[0]['exists']);
        $this->assertEmpty($events[0]['metadata']);
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_failure_event', $events[0]['eventName']);
    }

    public function testUserSignUp()
    {
        $email = 'test-user-signup@email.com';
        $username = 'someusername';

       $this->call(
           PostSpec::create('request', '/wp-login.php?action=register', [
               'Content-Type: application/x-www-form-urlencoded'
           ], "user_login=$username&user_email=$email&wp-submit=Register&redirect_to=")
       );

       $users = $this->connection()->query("SELECT * FROM wp_users where user_email='".$email."'")->fetchAll();

       $this->assertEquals(1, count($users));

       $signUpEvent = null;
       foreach(AppsecStatus::getInstance()->getEvents() as $event)
       {
           if ($event['eventName'] == 'track_user_signup_event') {
               $signUpEvent = $event;
           }
       }

       $this->assertTrue($signUpEvent['automated']);
       $this->assertEquals($users[0]['ID'], $signUpEvent['userId']);
       $this->assertEquals($users[0]['user_login'], $signUpEvent['metadata']['username']);
       $this->assertEquals($users[0]['user_email'], $signUpEvent['metadata']['email']);
    }
}
