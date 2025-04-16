<?php

namespace DDTrace\Tests\Integrations\Symfony\Latest;

class TraceSearchConfigTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\TraceSearchConfigTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Latest/public/index.php';
    }
}
