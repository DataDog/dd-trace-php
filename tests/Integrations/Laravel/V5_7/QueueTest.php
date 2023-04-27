<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

class QueueTest extends \DDTrace\Tests\Integrations\Laravel\V5_8\QueueTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }
}
