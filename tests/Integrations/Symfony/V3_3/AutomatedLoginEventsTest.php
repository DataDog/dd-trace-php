<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_3;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "symfony33";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_3/web/index.php';
    }

    public function deleteUsers()
    {
        $this->connection()->exec("DELETE from app_users where email LIKE 'test-user%'");
    }

    public function getUser($email)
    {
        return $this->connection()->query("SELECT * FROM app_users where email='".$email."'")->fetchAll();
    }

    public function createUser($email) {
         $this->connection()->exec('insert into app_users (email, username, password, is_active) VALUES ("'.$email.'", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i", 1)');
    }

    public function getSignUpPayload($email, $password) {
        return "user[email]=$email&user[username]=$email&user[plainPassword][first]=$password&user[plainPassword][second]=$password";
    }
}
