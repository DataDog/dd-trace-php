<?php

namespace DDTrace\Tests\Integrations\Symfony;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use datadog\appsec\AppsecStatus;

class AutomatedLoginEventsTestSuite extends AppsecTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->connection()->exec("DELETE from user where email LIKE 'test-user%'");
        AppsecStatus::getInstance()->setDefaults();
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        //Password is password
        $this->connection()->exec('insert into user (roles, email, password) VALUES ("", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');

         $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents(['track_user_login_success_event']);

         $this->assertEquals(1, count($events));
         $this->assertEquals($email, $events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
         $this->assertTrue($events[0]['automated']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';
        $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event']);
         $this->assertEquals(1, count($events));
         $this->assertEmpty($events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
         $this->assertTrue($events[0]['automated']);
    }

    public function testUserSignUp()
    {
       $email = 'test-user@email.com';
       $password = 'some password';
       $spec = PostSpec::create('Signup', '/register', [
                       'Content-Type: application/x-www-form-urlencoded'
                   ], "registration_form[email]=$email&registration_form[plainPassword]=$password&registration_form[agreeTerms]=1");

       $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

       $users = $this->connection()->query("SELECT * FROM user where email='".$email."'")->fetchAll();

        $this->assertEquals(1, count($users));

        $signUpEvent = AppsecStatus::getInstance()->getEvents(['track_user_signup_event']);

        $this->assertTrue($signUpEvent[0]['automated']);
        $this->assertEquals($email, $signUpEvent[0]['userId']);
    }
}
