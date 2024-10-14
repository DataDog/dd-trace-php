<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use Benchmarks\Integrations\FrameworkBenchmarksCase;

class EmptyFileBench extends FrameworkBenchmarksCase
{
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
        $this->call(GetSpec::create(
            'A web request to a framework not using auto loaders',
            '/'
        ));
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
        $this->call(GetSpec::create(
            'A web request to a framework not using auto loaders',
            '/'
        ));
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
