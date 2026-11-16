<?php

namespace DDTrace\Tests\Integrations\Symfony\Latest;

use DDTrace\Tests\Integrations\Symfony\AutomatedLoginEventsTestSuite;

/**
 * @group appsec
 */
class AutomatedLoginEventsTest extends AutomatedLoginEventsTestSuite
{
    public static $database = "symfonyLatest";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Latest/public/index.php';
    }

    public function createUser($email) {
         $this->connection()->exec('insert into user (roles, email, password) VALUES ("{}", "'.$email.'", "$2y$13$WNnAxSuifzgXGx9kYfFr.eMaXzE50MmrMnXxmrlZqxSa21oiMyy0i")');
    }
}
