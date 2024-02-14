<?php

namespace DDTrace\Tests\Integrations\Laravel\V9_x;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use datadog\appsec\AppsecStatus;

class LoginEventsTest extends \DDTrace\Tests\Integrations\Laravel\V8_x\LoginEventsTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_9_x/public/index.php';
    }
}
