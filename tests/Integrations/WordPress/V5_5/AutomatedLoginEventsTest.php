<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use datadog\appsec\AppsecStatus;

 /**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    protected function databaseDump() {
        return file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from users where email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        $id = 123;
        $name = 'some name';
        //Password is test
        $this->connection()->exec(
            'INSERT INTO wp55_users VALUES ('.$id.',"test","$P$BDzpK1XXL9P2cYWggPMUbN87GQSiI80","test","'.$email.'","","2020-10-22 16:31:15","",0,"'.$name.'")'
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
            'INSERT INTO wp55_users VALUES ('.$id.',"test","$P$BDzpK1XXL9P2cYWggPMUbN87GQSiI80","test","'.$email.'","","2020-10-22 16:31:15","",0,"'.$name.'")'
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

       $users = $this->connection()->query("SELECT * FROM wp55_users where user_email='".$email."'")->fetchAll();

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
