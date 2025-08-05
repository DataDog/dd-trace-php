<?php

namespace DDTrace\Tests\Integrations\WordPress;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

/**
 * @group appsec
 */
class AutomatedLoginEventsTestSuite extends AppsecTestCase
{
    protected $users_table = 'wp_users';
    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from ".$this->users_table." where user_email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    protected function createUser($id, $name, $email)
    {
        $this->connection()->exec(
            'INSERT INTO '.$this->users_table.' VALUES ('.$id.',"test-login","$P$BDzpK1XXL9P2cYWggPMUbN87GQSiI80","test","'.$email.'","","2020-10-22 16:31:15","",0,"'.$name.'")'
        );
    }

    protected function login($email, $password)
    {
        $this->call(PostSpec::create('request', '/wp-login.php', [
            'Content-Type: application/x-www-form-urlencoded'
        ], "log=$email&pwd=$password&wp-submit=Log In"));
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        $id = 123;
        $name = 'some name';

        $this->createUser($id, $name, $email);
        $this->login($email, $password);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_success_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($events));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($email, $events[0]['userLogin']);
        $this->assertEquals($id, $events[0]['userId']);
        $this->assertEquals($email, $events[0]['metadata']['email']);
        $this->assertEquals($name, $events[0]['metadata']['name']);
    }

    public function testHittingLoginPageDoesNotGenerateUserEvent()
    {
        $spec = GetSpec::create('request', '/wp-login.php');
        $this->call($spec, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIESESSION => true]);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
        $this->assertEquals(0, count($events));
    }

    public function testUserLoginFailureEventWhenUserDoesNotExists()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';

        $this->login($email, $password);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($events));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($email, $events[0]['userId']);
        $this->assertEquals($email, $events[0]['userLogin']);
        $this->assertFalse($events[0]['exists']);
        $this->assertEmpty($events[0]['metadata']);
    }

    public function testUserLoginFailureEventWhenUserDoesExists()
    {
        $email = 'test-user-2@email.com';
        $password = 'invalid';
        $id = 333;
        $name = 'some name';

        $this->createUser($id, $name, $email);
        $this->login($email, $password);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($events));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($email, $events[0]['userId']);
        $this->assertEquals($email, $events[0]['userLogin']);
        $this->assertTrue($events[0]['exists']);
        $this->assertEmpty($events[0]['metadata']);
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

        $users = $this->connection()->query("SELECT * FROM ".$this->users_table." where user_email='".$email."'")->fetchAll();

        $this->assertEquals(1, count($users));

        $signUpEvent = AppsecStatus::getInstance()->getEvents(['track_user_signup_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($signUpEvent));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($users[0]['ID'], $signUpEvent[0]['userId']);
        $this->assertEquals($users[0]['user_login'], $signUpEvent[0]['userLogin']);
        $this->assertEquals($users[0]['user_login'], $signUpEvent[0]['metadata']['username']);
        $this->assertEquals($users[0]['user_email'], $signUpEvent[0]['metadata']['email']);
    }

    public function testLoggedInCalls()
    {
        $this->enableSession();
        $email = 'test-user@email.com';
        $password = 'test';
        $id = 123;
        $name = 'some name';

        $this->createUser($id, $name, $email);
        $this->login($email, $password);

        AppsecStatus::getInstance()->setDefaults(); //Remove all events

        $this->call(GetSpec::create('Behind Auth', '/'));

        $loginEvents = AppsecStatus::getInstance()->getEvents([
            'track_user_login_success_event_automated',
            'track_user_login_failure_event_automated',
            'track_user_signup_event_automated'
        ]);

        $authenticatedEvents = AppsecStatus::getInstance()->getEvents([
            'track_authenticated_user_event_automated'
        ]);

        $this->assertEquals(0, count($loginEvents));
        $this->assertEquals(1, count($authenticatedEvents));
        $this->assertEquals($id, $authenticatedEvents[0]['userId']);
        $this->disableSession();
    }
}
