<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

abstract class FrameworkBenchmarksCase extends WebFrameworkTestCase
{
    public function disableDatadog()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 0,
            'DD_APPSEC_ENABLED' => 0,
        ]);
    }

    public function enableDatadog()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
            'DD_APPSEC_ENABLED' => 1,
        ]);
    }
}
