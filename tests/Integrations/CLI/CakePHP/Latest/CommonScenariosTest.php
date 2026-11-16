<?php

namespace DDTrace\Tests\Integrations\CLI\CakePHP\Latest;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\CakePHP\V3_10\CommonScenariosTest
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/CakePHP/Latest/bin/cake.php';
    }
}