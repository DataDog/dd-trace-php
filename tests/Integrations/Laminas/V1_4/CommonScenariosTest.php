<?php

namespace DDTrace\Tests\Integrations\Laminas\V1_4;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laminas\V2_0\CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laminas/Version_1_4/public/index.php';
    }
}
