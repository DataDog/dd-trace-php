<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

abstract class FrameworkBenchmarksCase extends WebFrameworkTestCase
{
    protected function getClassName()
    {
        $fullyQualified = get_class($this);
        $tokens = explode('\\', $fullyQualified);
        return end($tokens);
    }

    public function disableDatadog()
    {
        $name = $this->getClassName();
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 0,
            'DD_APPSEC_ENABLED' => 0,
        ], ['error_log' => "/tmp/logs/$name.log"]);
    }

    public function enableDatadog()
    {
        $name = $this->getClassName();
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
            'DD_APPSEC_ENABLED' => 1,
        ], ['error_log' => "/tmp/logs/$name.log"]);
    }
}
