<?php

namespace DDTrace\Tests\Composer;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use PHPUnit\Framework\TestCase;

class ComposerInteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

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
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    public function testPreloadDDTraceNotUsedManualTracing()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/manual-tracing',
                    'http.status_code' => '200',
                ])
                ->withChildren([
                    SpanAssertion::build('my_operation', 'web.request', 'memcached', 'my_resource')
                        ->withExactTags([
                            'http.method' => 'GET',
                        ]),
                ]),
        ]);
    }

    public function testPreloadDDTraceUsedNoManualTracing()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    public function testPreloadDDTraceUsedManualTracing()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/manual-tracing',
                    'http.status_code' => '200',
                ])
                ->withChildren([
                    SpanAssertion::build('my_operation', 'web.request', 'memcached', 'my_resource')
                        ->withExactTags([
                            'http.method' => 'GET',
                        ]),
                ]),
        ]);
    }

    public function testNoPreloadNoManualTracing()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    public function testNoComposerNoPreload()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-composer'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-composer')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/no-composer',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    public function testNoComposerYesPreload()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        //$this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-composer'));
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

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-composer')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/no-composer',
                    'http.status_code' => '200',
                ]),
        ]);
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

    /**
     * Returns the path to a file used to inspect whether opcache preloading was executed.
     * @return string
     */
    private function getPreloadTouchFilePath()
    {
        return __DIR__ . '/app/touch.preload';
    }
}
