<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Tests\Common\MultiPHPUnitVersionAdapter;
use DDTrace\Log\Logger;
use DDTrace\Tests\DebugLogger;
use DDTrace\Util\Versions;
use PHPUnit\Framework;

abstract class BaseTestCase extends MultiPHPUnitVersionAdapter
{
    protected function afterSetUp()
    {
    }

    protected function beforeTearDown()
    {
        \Mockery::close();
        Logger::reset();
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function matchesPhpVersion($version)
    {
        return Versions::phpVersionMatches($version);
    }

    /**
     * Sets and return a debug logger which accumulates log messages.
     * @return DebugLogger
     */
    protected function withDebugLogger()
    {
        $logger = new DebugLogger();
        Logger::set($logger);
        return $logger;
    }


    public function composerUpdateScenario($workingDir)
    {
        exec(
            "composer --working-dir='$workingDir' update -q",
            $output,
            $return
        );
        if (0 !== $return) {
            $this->fail('Error while preparing the env: ' . implode("\n", $output));
        }
    }

    /**
     * Reloads configuration setting first the envs in $putenvs
     *
     * @param array $putenvs In the format ['ENV_1=value1', 'ENV_2=value2']
     * @return void
     */
    protected function putEnvAndReloadConfig($putenvs = [])
    {
        foreach ($putenvs as $putenv) {
            \putenv($putenv);
        }
        \dd_trace_internal_fn('ddtrace_reload_config');
    }
}
