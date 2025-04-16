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
    /**
     * @BeforeMethods("disableDatadog")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchSymfonyBaseline()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
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
    public function benchSymfonyOverhead()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
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
