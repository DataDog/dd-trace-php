<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\Utils;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class SymfonyBench extends WebFrameworkTestCase
{
    use TracerTestTrait;

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Symfony/Version_6_2/public/index.php';
    }

    public function disableSymfonyTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 0,
        ]);
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }

    public function enableSymfonyTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
        ]);
    }

    /**
     * @BeforeMethods("disableSymfonyTracing")
     * @AfterMethods("afterMethod")
     * @Revs(5)
     * @Iterations(5)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchSymfonyBaseline()
    {
        Utils::call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    /**
     * @BeforeMethods("enableSymfonyTracing")
     * @AfterMethods("afterMethod")
     * @Revs(5)
     * @Iterations(5)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchSymfonyOverhead()
    {
        Utils::call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }
}
