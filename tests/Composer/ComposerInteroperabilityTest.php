<?php

namespace DDTrace\Tests\Composer;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Common\BaseTestCase;
use PHPUnit\Framework\TestCase;

class ComposerInteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        if (\file_exists($this->getPreloadTouchFilePath())) {
            \unlink($this->getPreloadTouchFilePath());
        }
    }

    public function testComposerInteroperabilityWhenNoInitHook()
    {
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            ],
            [
                'ddtrace.request_init_hook' => 'do_not_exists',
            ]
        );

        $this->assertEmpty($traces);
    }

    public function testComposerInteroperabilityWhenInitHookWorks()
    {
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [
                'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
            ],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    public function testPreloadDDTraceNotUsedNoManualTracing()
    {
        $this->assertFileDoesNotExist($this->getPreloadTouchFilePath());
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    public function testPreloadDDTraceNotUsedManualTracing()
    {
        $this->assertFileDoesNotExist($this->getPreloadTouchFilePath());
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    public function testPreloadDDTraceUsedNoManualTracing()
    {
        $this->assertFileDoesNotExist($this->getPreloadTouchFilePath());
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes USED in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.ddtrace.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    public function testPreloadDDTraceUsedManualTracing()
    {
        $this->assertFileDoesNotExist($this->getPreloadTouchFilePath());
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes USED in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.ddtrace.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    public function testNoPreloadNoManualTracing()
    {
        $this->assertFileDoesNotExist($this->getPreloadTouchFilePath());
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    /**
     * Given a path to a composer working directory, this method runs composer update in it.
     *
     * @param string $workingDir
     */
    private function composerUpdateScenario($workingDir)
    {
        exec(
            "composer --working-dir='$workingDir' update -q",
            $output,
            $return
        );
        if (0 !== $return) {
            $this->fail('Error while preparing the env: ' . implode("", $output));
        }
    }

    private function getPreloadTouchFilePath()
    {
        return __DIR__ . '/app/touch.preload';
    }
}
