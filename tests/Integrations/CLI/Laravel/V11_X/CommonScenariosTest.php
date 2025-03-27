<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V11_X;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Laravel\V10_X\CommonScenariosTest
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_11_x/artisan';
    }
}