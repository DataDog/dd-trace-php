<?php

namespace DDTrace\Tests\Integrations\CakePHP\Latest;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CakePHP\V4_5\CommonScenariosTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Latest/webroot/index.php';
    }
}