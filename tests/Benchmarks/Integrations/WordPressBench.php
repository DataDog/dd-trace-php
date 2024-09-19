<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

/**
* @Groups({"frameworks"})
*/
class WordPressBench extends FrameworkBenchmarksCase
{
    /**
     * @BeforeMethods({"enableDatadog", "createDatabase"})
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchWordPressOverhead()
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

    public function createDatabase(): void
    {
        $pdo = new \PDO('mysql:host=mysql_integration', 'test', 'test');
        $pdo->exec('CREATE DATABASE IF NOT EXISTS wp61');
        $pdo->exec(file_get_contents(__DIR__ . '/../../Frameworks/WordPress/Version_6_1/scripts/wp_initdb.sql'));
    }

    public function afterMethod()
    {
        $this->TearDownAfterClass();
    }

    /**
     * @BeforeMethods({"disableDatadog", "createDatabase"})
     * @AfterMethods("afterMethod")
     * @Revs(10)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchWordPressBaseline()
    {
        $this->call(GetSpec::create(
            'A simple GET request with a view',
            '/simple_view?key=value&pwd=should_redact'
        ));
    }
}
