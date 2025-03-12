<?php

namespace DDTrace\Tests\Integrations\Symfony;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\PostSpec;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
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

    public function createUser($email)
    {
        $this->connection()->exec('insert into user (roles, email, password) VALUES ("", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');
    }

    public function login($email, $password)
    {
        $this->call(PostSpec::create('request', '/login', [
            'Content-Type: application/x-www-form-urlencoded'
        ], "_username=$email&_password=$password"));
    }

    public function getSignUpPayload($email, $password)
    {
        return "registration_form[email]=$email&registration_form[plainPassword]=$password&registration_form[agreeTerms]=1";
    }

    public function testUserLoginSuccessEvent()
    {
        $email = 'test-user@email.com';
        $password = 'test';
        $this->createUser($email);
        $this->login($email, $password);

        $loginEvents = AppsecStatus::getInstance()->getEvents(['track_user_login_success_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($loginEvents));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($email, $loginEvents[0]['userLogin']);
        $this->assertEquals($email, $loginEvents[0]['userId']);
        $this->assertEmpty($loginEvents[0]['metadata']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'non-existing@email.com';
        $password = 'some password';

        $this->login($email, $password);

        $loginEvents = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($loginEvents));
        $this->assertEquals(0, count($authEvents));

        $this->assertEmpty($loginEvents[0]['userLogin']);
        $this->assertEmpty($loginEvents[0]['userId']);
        $this->assertEmpty($loginEvents[0]['metadata']);
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

        $signUpEvents = AppsecStatus::getInstance()->getEvents(['track_user_signup_event_automated']);
        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(1, count($signUpEvents));
        $this->assertEquals(0, count($authEvents));

        $this->assertEquals($email, $signUpEvents[0]['userLogin']);
        $this->assertEquals($email, $signUpEvents[0]['userId']);
        $this->assertEmpty($signUpEvents[0]['metadata']);
    }

    public function testLoggedInCalls()
    {
        $this->enableSession();

        $email = 'test-user@email.com';
        $password = 'test';
        $this->createUser($email);
        $this->login($email, $password);

        AppsecStatus::getInstance()->setDefaults(); //Remove all events

        $this->call(GetSpec::create('Behind Auth', '/behind_auth'));

        $loginEvents = AppsecStatus::getInstance()->getEvents([
            'track_user_login_success_event_automated',
            'track_user_login_failure_event_automated',
            'track_user_signup_event_automated'
        ]);

        $authEvents = AppsecStatus::getInstance()->getEvents(['track_authenticated_user_event_automated']);

        $this->assertEquals(0, count($loginEvents));
        $this->assertEquals(1, count($authEvents));
        $this->assertEquals($email, $authEvents[0]['userId']);

        $this->disableSession();
    }
}
