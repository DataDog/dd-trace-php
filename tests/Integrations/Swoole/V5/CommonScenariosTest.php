<?php

namespace DDTrace\Tests\Integrations\Swoole\V5;

use DDTrace\Tests\Common\SnapshotTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Swoole/Version_5/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'swoole_test_app',
            'DD_TRACE_DEBUG' => 'true',
            'DD_TRACE_CLI_ENABLED' => 'true',
        ]);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'extension' => 'swoole.so',
        ]);
    }

    public function testScenarioGetReturnString()
    {
        $this->tracesFromWebRequestSnapshot(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact'
                )
            );
        });
    }
}
