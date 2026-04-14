<?php

namespace DDTrace\Tests\Integrations\Laminas\Mvc;

use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class AutomatedLoginEventsTestSuite extends AppsecTestCase
{
    public static $database = "test";

    protected function databaseDump()
    {
        return <<<SQL
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL;
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

    protected function createUser($id, $name, $email)
    {
        //Password is password (MD5 hash)
        $password = md5('password');
        $this->connection()->exec("insert into users (id, name, email, password) VALUES (".$id.", '".$name."', '".$email."', '".$password."')");
    }

    public function testUserLoginSuccessEvent()
    {
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        $this->createUser($id, $name, $email);

        $this->login($email);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_success_event_automated']);
        $this->assertEquals(1, count($events));
        $this->assertEquals($id, $events[0]['userId']);
        $this->assertEquals($email, $events[0]['userLogin']);
        $this->assertEquals($name, $events[0]['metadata']['name']);
        $this->assertEquals($email, $events[0]['metadata']['email']);
    }

    public function testUserLoginFailureEvent()
    {
        $email = 'test-user-non-existing@email.com';

        $this->login($email);

        $events = AppsecStatus::getInstance()->getEvents(['track_user_login_failure_event_automated']);
        $this->assertEquals(1, count($events));
        $this->assertEquals($email, $events[0]['userLogin']);
    }

    public function testAuthenticatedUserEventAfterLogin()
    {
        $this->enableSession();
        $id = 1234;
        $name = 'someName';
        $email = 'test-user@email.com';
        $this->createUser($id, $name, $email);

        $this->login($email);

        AppsecStatus::getInstance()->setDefaults();
        $this->call(GetSpec::create('Behind auth', '/behind_auth'));

        $loginEvents = AppsecStatus::getInstance()->getEvents([
            'track_user_login_success_event_automated',
            'track_user_login_failure_event_automated',
        ]);

        $authenticatedEvents = AppsecStatus::getInstance()->getEvents([
            'track_authenticated_user_event_automated'
        ]);

        $this->assertEquals(0, count($loginEvents));
        $this->assertGreaterThanOrEqual(1, count($authenticatedEvents));
        $this->assertEquals($id, $authenticatedEvents[0]['userId']);
        $this->disableSession();
    }
}
