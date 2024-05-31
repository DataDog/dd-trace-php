<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_8;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\Laravel\V5_7\CommonScenariosTest
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_8/public/index.php';
    }
}
