<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_3;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "symfony73";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_3/public/index.php';
    }

    public function createUser($email) {
         $this->connection()->exec('insert into user (roles, email, password) VALUES ("{}", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');
    }
}
