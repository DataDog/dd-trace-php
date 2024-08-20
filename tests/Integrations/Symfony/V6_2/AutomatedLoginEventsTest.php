<?php

namespace DDTrace\Tests\Integrations\Symfony\V6_2;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "symfony62";

    public function createUser($email) {
         $this->connection()->exec('insert into user (roles, email, password) VALUES ("{}", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');
    }


    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_6_2/public/index.php';
    }
}
