<?php

namespace DDTrace\Tests\Integrations\CLI\CakePHP\V5_0;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\CakePHP\V3_10\CommonScenariosTest
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/CakePHP/Version_5_0/bin/cake.php';
    }
}
