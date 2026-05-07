<?php

namespace DDTrace\Tests\Integrations\Symfony\V7_3;

class TraceSearchConfigTest extends \DDTrace\Tests\Integrations\Symfony\V6_2\TraceSearchConfigTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_7_3/public/index.php';
    }
}
