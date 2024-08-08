<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

class QueueTest extends \DDTrace\Tests\Integrations\Laravel\V5_8\QueueTest
{
    public static $database = "laravel57";

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }
}
