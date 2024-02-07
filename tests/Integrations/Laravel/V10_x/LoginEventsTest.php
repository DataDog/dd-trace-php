<?php

namespace DDTrace\Tests\Integrations\Laravel\V10_x;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class LoginEventsTest extends WebFrameworkTestCase
{
    protected $maintainSession = true;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_10_x/public/index.php';
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
        return $this->tracesFromWebRequest(function () use ($email) {
            $this->call(
                GetSpec::create('Login success event', '/login/auth?email='.$email)
            );
        });
    }

    protected function createUser($id, $name, $email) {
        //Password is password
        $this->connection()->exec("insert into users (id, name, email, password) VALUES (".$id.", '".$name."', '".$email."', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')");
    }

    public function testLoggedInCalls()
    {
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        $this->createUser($id, $name, $email);

        //First log in
        $traces = $this->login($email);

        $meta = $traces[0][0]['meta'];
        $this->assertEquals($id, $meta['usr.id']);
        $this->assertEquals($name, $meta['usr.name']);
        $this->assertEquals($email, $meta['usr.email']);

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(1, count($events));
        $this->assertEquals($id, $events[0]['userId']);
        $this->assertEquals($name, $events[0]['metadata']['name']);
        $this->assertEquals($email, $events[0]['metadata']['email']);
        $this->assertTrue($events[0]['automated']);
        $this->assertEquals('track_user_login_success_event', $events[0]['eventName']);

        //Now we are logged in lets do another call
        AppsecStatus::getInstance()->setDefaults(); //Remove all events
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Behind auth', '/behind_auth'));
        });

        $events = AppsecStatus::getInstance()->getEvents();
        $this->assertEquals(0, count($events)); //Auth does not generate appsec events
        $meta = $traces[0][0]['meta'];
        $this->assertEquals($id, $meta['usr.id']);
        $this->assertEquals($name, $meta['usr.name']);
        $this->assertEquals($email, $meta['usr.email']);
    }
}
