<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use Benchmarks\Integrations\FrameworkBenchmarksCase;

class EmptyFileBench extends FrameworkBenchmarksCase
{
    public function doRun()
    {
        $this->call(GetSpec::create(
            'A web request to a framework not using auto loaders',
            '/'
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
    public function benchEmptyFileBaseline()
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
    public function benchEmptyFileOverhead()
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
    public function benchEmptyFileDdprof()
    {
        $this->doRun();
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }
}
