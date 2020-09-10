<?php

namespace DDTrace\Tests;

use PHPUnit\Framework\BaseTestListener;
use PHPUnit_Framework_Test;

class DDTestListener extends BaseTestListener
{
    public function startTest(PHPUnit_Framework_Test $test)
    {
        \dd_trace_internal_fn('ddtrace_reload_config');
    }

    public function endTest(\PHPUnit_Framework_Test $test, $time)
    {
        \dd_trace_internal_fn('ddtrace_reload_config');
    }
}
