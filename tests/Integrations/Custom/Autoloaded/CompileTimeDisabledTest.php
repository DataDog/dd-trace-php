<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class CompileTimeDisabledTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_MEASURE_COMPILE_TIME' => '0',
        ]);
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        /* The span encoder of this process gets used to convert the trace's spans into an array.
         * For the compile-time metrics specifically, this goofs things up, so let's disable.
         */
        \putenv('DD_TRACE_MEASURE_COMPILE_TIME=0');
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddTearDown()
    {
        \putenv('DD_TRACE_MEASURE_COMPILE_TIME');
        dd_trace_internal_fn('ddtrace_reload_config');
        parent::ddTearDown();
    }

    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Compile time does not exist on root span', '/simple');
            $this->call($spec);
        });

        self::assertFalse(isset($traces[0][0]['metrics']['php.compilation.total_time_ms']));
    }
}
