<?php

namespace DDTrace\Tests\Integrations\Symfony;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use datadog\appsec\AppsecStatus;

abstract class AutomatedLoginEventsTestSuite extends AppsecTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->deleteUsers();
        AppsecStatus::getInstance()->setDefaults();
    }

    public function deleteUsers()
    {
        $this->connection()->exec("DELETE from user where email LIKE 'test-user%'");
    }

    public function getUser($email)
    {
        return $this->connection()->query("SELECT * FROM user where email='".$email."'")->fetchAll();
    }

    public function createUser($email) {
         $this->connection()->exec('insert into user (roles, email, password) VALUES ("", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        $this->createUser($email);

         $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents(['track_user_login_success_event_automated']);

         $this->assertEquals(1, count($events));
         $this->assertEquals($email, $events[0]['userLogin']);
         $this->assertEquals($email, $events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';
        $spec = PostSpec::create('request', '/login', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], "_username=$email&_password=$password");

         $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

         $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
         $this->assertEquals(1, count($events));
         $this->assertEmpty($events[0]['userLogin']);
         $this->assertEmpty($events[0]['userId']);
         $this->assertEmpty($events[0]['metadata']);
    }

    public function getSignUpPayload($email, $password) {
        return "registration_form[email]=$email&registration_form[plainPassword]=$password&registration_form[agreeTerms]=1";
    }

    public function testUserSignUp()
    {
        $email = 'test-user@email.com';
        $password = 'some password';
        $spec = PostSpec::create('Signup', '/register', [
                        'Content-Type: application/x-www-form-urlencoded'
                    ], $this->getSignUpPayload($email, $password));

        $this->call($spec, [ CURLOPT_FOLLOWLOCATION => false ]);

        $users = $this->getUser($email);

        $this->assertEquals(1, count($users));

        $signUpEvent = AppsecStatus::getInstance()->getEvents(['track_user_signup_event_automated']);

        $this->assertEquals($email, $signUpEvent[0]['userLogin']);
        $this->assertEquals($email, $signUpEvent[0]['userId']);
        $this->assertEmpty($signUpEvent[0]['metadata']);
    }
}
