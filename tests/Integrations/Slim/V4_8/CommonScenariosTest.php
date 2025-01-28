<?php

namespace DDTrace\Tests\Integrations\Slim\V4_8;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Slim\Latest\CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_4_8/public/index.php';
    }
}
