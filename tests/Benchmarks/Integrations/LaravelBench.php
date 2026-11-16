<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

/**
* @Groups({"frameworks"})
*/
class LaravelBench extends FrameworkBenchmarksCase
{
    public function doRun()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    /**
     * @BeforeMethods("disableDatadog")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchLaravelBaseline()
    {
        $this->doRun();
    }

    /**
     * @BeforeMethods("enableDatadog")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchLaravelOverhead()
    {
        $this->doRun();
    }

    /**
     * @BeforeMethods({"enableDatadogWithDdprof"})
     * @AfterMethods("afterMethod")
     * @Revs(1)
     * @Iterations(50)
     * @OutputTimeUnit("microseconds")
     * @Groups({"ddprof"})
     * @Warmup(1)
     */
    public function benchLaravelDdprof()
    {
        $this->doRun();
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Laravel/Version_10_x/public/index.php';
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }
}
