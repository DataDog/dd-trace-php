<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V9_X;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Laravel\V8_X\CommonScenariosTest
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_9_x/artisan';
    }
}
