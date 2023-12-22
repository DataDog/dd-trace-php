<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\Utils;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class LaravelBench extends WebFrameworkTestCase
{
    use TracerTestTrait;

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

    /**
     * @BeforeMethods("disableLaravelTracing")
     * @AfterMethods("afterMethod")
     * @Revs(1)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchLaravelBaseline()
    {
        Utils::call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    /**
     * @BeforeMethods("enableLaravelTracing")
     * @AfterMethods("afterMethod")
     * @Revs(1)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     */
    public function benchLaravelOverhead()
    {
        Utils::call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }
}
