<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class EmptyFileBench extends WebFrameworkTestCase
{
    /**
     * @BeforeMethods("disabledTracing")
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
     * @BeforeMethods("enableTracing")
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

    public function disabledTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 0,
        ]);
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }

    public function enableTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
        ]);
    }
}
