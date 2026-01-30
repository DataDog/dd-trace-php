<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

/**
* @Groups({"frameworks"})
*/
class SymfonyBench extends FrameworkBenchmarksCase
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
     * @Warmup(5)
     */
    public function benchSymfonyBaseline()
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
    public function benchSymfonyOverhead()
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
     * @Warmup(5)
     */
    public function benchSymfonyDdprof()
    {
        $this->doRun();
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Symfony/Version_5_2/public/index.php';
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }
}
