<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class WordPressBench extends WebFrameworkTestCase
{
    /**
     * @BeforeMethods("enableWordPressTracing")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     * @Groups({"overhead"})
     */
    public function benchWordPressOverhead()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    /**
     * @BeforeMethods("enableEnhancedWordPressTracing")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     * @Groups({"overhead"})
     */
    public function benchEnhancedWordPressOverhead()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/WordPress/Version_6_1/index.php';
    }

    public function disableWordPressTracing()
    {
        $pdo = new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
        $pdo->exec(file_get_contents(__DIR__ . '/../../Frameworks/WordPress/Version_6_1/scripts/wp_initdb.sql'));
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 0,
        ]);
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }

    public function enableWordPressTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1
        ]);
    }

    public function enableEnhancedWordPressTracing()
    {
        $this->setUpWebServer([
            'DD_TRACE_ENABLED' => 1,
            'DD_TRACE_WORDPRESS_ENHANCED_INTEGRATION' => '1'
        ]);
    }

    /**
     * @BeforeMethods("disableWordPressTracing")
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     * @Groups({"baseline"})
     */
    public function benchWordPressBaseline()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }
}
