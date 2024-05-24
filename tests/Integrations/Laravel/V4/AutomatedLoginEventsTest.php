<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from users where email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    protected function login($email)
    {
        $this->call(
            GetSpec::create('Login success event', '/login/auth?email='.$email)
        );
    }

    protected function createUser($id, $name, $email) {
        //Password is password
        $this->connection()->exec("insert into users (id, name, email, password) VALUES (".$id.", '".$name."', '".$email."', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')");
    }

    public function testUserLoginSuccessEvent()
    {
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        $this->createUser($id, $name, $email);

        $this->login($email);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($id, $events[0]['userId']);
        $this->assertEquals($name, $events[0]['metadata']['name']);
        $this->assertEquals($email, $events[0]['metadata']['email']);
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_success_event', $events[0]['eventName']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'test-user-non-existing@email.com';

        $this->login($email);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_failure_event', $events[0]['eventName']);
    }

    public function testUserSignUp()
    {
        $email = 'test-user-new@email.com';
        $name = 'somename';
        $password = 'somepassword';

       $this->call(
           GetSpec::create('Signup', sprintf('/login/signup?email=%s&name=%s&password=%s',$email, $email, $password))
       );

       $users = $this->connection()->query("SELECT * FROM users where email='".$email."'")->fetchAll();

        $this->assertEquals(1, count($users));

        $signUpEvent = null;
        foreach(AppsecStatus::getInstance()->getEvents() as $event)
        {
            if ($event['eventName'] == 'track_user_signup_event') {
                $signUpEvent = $event;
            }
        }

        $this->assertTrue($signUpEvent['automated']);
        $this->assertEquals($users[0]['id'], $signUpEvent['userId']);
    }

    public function testLoggedInCalls()
    {
        $this->enableSession();
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        $this->createUser($id, $name, $email);

        //First log in
        $this->login($email);

        //Now we are logged in lets do another call
        AppsecStatus::getInstance()->setDefaults(); //Remove all events
        $this->call(GetSpec::create('Behind auth', '/behind_auth'));

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(0, count($events)); //Auth does not generate appsec events
        $this->disableSession();
    }
}
