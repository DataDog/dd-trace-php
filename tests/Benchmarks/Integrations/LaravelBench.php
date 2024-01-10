<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class LaravelBench extends WebFrameworkTestCase
{
    /**
     * @BeforeMethods("disableLaravelTracing")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchLaravelBaseline()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    /**
     * @BeforeMethods("enableLaravelTracing")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchLaravelOverhead()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Laravel/Version_10_x/public/index.php';
    }

    public function disableLaravelTracing()
    {
        $this->setUpWebServer([
                'DD_TRACE_ENABLED' => 0,
        ]);
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }

    public function enableLaravelTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
        ]);
    }
}
