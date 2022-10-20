<?php

namespace DDTrace\Tests\Integrations\Symfony\V4_0;

use DDTrace\Tests\Common\IntegrationTestCase;

class LongRunningScriptTest extends IntegrationTestCase
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_4_0/bin/console';
    }

    public function testScenario()
    {
        list($traces) = $this->inCli(self::getConsoleScript(), [
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
        ], [], 'about');

        $this->assertEmpty($traces);
    }
}
