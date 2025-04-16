<?php

namespace DDTrace\Tests\Integrations\Nette\V3_1;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Type;

class NetteTest extends \DDTrace\Tests\Integrations\Nette\Latest\NetteTest
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Nette/Version_3_1/www/index.php';
    }
}
