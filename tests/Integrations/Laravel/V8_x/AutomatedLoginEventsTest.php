<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class AutomatedLoginEventsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
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
        $this->connection()->exec("DELETE from users where email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    public static function ddTearDownAfterClass()
    {
        AppsecStatus::getInstance()->destroy();
        parent::ddTearDownAfterClass();
    }

    protected function login($email)
    {
        $this->call(
            GetSpec::create('Login success event', '/login/auth?email='.$email)
        );
    }

    public function testUserLoginSuccessEvent()
    {
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        //Password is password
        $this->connection()->exec("insert into users (id, name, email, password) VALUES (".$id.", '".$name."', '".$email."', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')");

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
}
