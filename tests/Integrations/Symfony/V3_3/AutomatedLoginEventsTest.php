<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_3;

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
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_3/web/index.php';
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from app_users where email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        //Password is password
        $query = 'insert into app_users (email, username, password, is_active) VALUES ("'.$email.'", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i", 1)';
        $this->connection()->exec($query);

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

    public function testUserSignUp()
    {
       $email = 'test-user@email.com';
       $password = 'some password';
       $spec = PostSpec::create('Signup', '/register', [
                       'Content-Type: application/x-www-form-urlencoded'
                   ], "user[email]=$email&user[username]=$email&user[plainPassword][first]=$password&user[plainPassword][second]=$password");

       $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

       $users = $this->connection()->query("SELECT * FROM app_users where email='".$email."'")->fetchAll();

        $this->assertEquals(1, count($users));

        $signUpEvent = null;
        foreach(AppsecStatus::getInstance()->getEvents() as $event)
        {
            if ($event['eventName'] == 'track_user_signup_event') {
                $signUpEvent = $event;
            }
        }

        $this->assertTrue($signUpEvent['automated']);
        $this->assertEquals($email, $signUpEvent['userId']);
    }

}
