<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class StartupLoggingDiagnosticsDisabledTest extends WebFrameworkTestCase
{
    const IS_SANDBOX = true;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    private static function getAppErrorLog()
    {
        return \dirname(static::getAppIndexScript()) . '/startup_logging.log';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_DEBUG' => false, // Will not emit diagnostic messages
            'DD_AGENT_HOST' => 'invalid_host', // Will fail diagnostic check
        ]);
    }

    protected function setUp()
    {
        parent::setUp();

        // clear out any previous logs
        $log = self::getAppErrorLog();
        @\unlink($log);
        \touch($log);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'error_log' => self::getAppErrorLog(),
            'ddtrace.request_init_hook' => '/foo/invalid.php', // Will fail diagnostic check
        ]);
    }

    public function testDiagnosticChecksNotLoggedWhenDebugModeDisabled()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Startup logs diagnostics disabled test', '/simple');
            $this->call($spec);
        });

        $contents = \file_get_contents(self::getAppErrorLog());

        self::assertNotContains('DATADOG TRACER DIAGNOSTICS', $contents);
        self::assertContains('DATADOG TRACER CONFIGURATION', $contents);
    }
}
