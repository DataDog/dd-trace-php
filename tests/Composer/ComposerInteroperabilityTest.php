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

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        self::composerUpdateScenario(__DIR__ . '/app');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        if (\file_exists($this->getPreloadTouchFilePath())) {
            \unlink($this->getPreloadTouchFilePath());
        }
    }

    public function testComposerInteroperabilityWhenNoInitHook()
    {
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
                'datadog.trace.sources_path' => 'do_not_exists',
            ]
        );

        $this->assertEmpty($traces);
    }

    public function testComposerInteroperabilityWhenSourcesValid()
    {
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
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    /**
     * Simulates an autoloading scenario when preloading and composer are used. Manual tracing is not done, but DDTrace
     * classes are not used in the preloading script.
     */
    public function testPreloadDDTraceNotUsedNoManualTracing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when preloading and composer are used. Manual tracing is done, but DDTrace
     * classes are not used in the preloading script.
     */
    public function testPreloadDDTraceNotUsedManualTracing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/manual-tracing',
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

    /**
     * Simulates an autoloading scenario when preloading and composer are used. Manual tracing is done, but DDTrace
     * classes are not used in the preloading script, but composer is used in the preloading script.
     */
    public function testPreloadDDTraceNotUsedWithComposerManualTracing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.composer.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/manual-tracing',
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

    /**
     * Simulates an autoloading scenario when preloading and composer are used. Moreover, DDTrace classes are
     * referenced in the preloading script, but no manual tracing is performed.
     */
    public function testPreloadDDTraceUsedNoManualTracing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes USED in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when preloading and composer are used. Moreover, DDTrace classes are referenced
     * in the preloading script.
     */
    public function testPreloadDDTraceUsedManualTracing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/manual-tracing'));
                TestCase::assertSame("OK - preload:'DDTrace classes USED in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/manual-tracing',
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

    /**
     * Simulates the basic scenario when neither preloading nor manual tracing are used.
     */
    public function testNoPreloadNoManualTracing()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-manual-tracing'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-manual-tracing')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-manual-tracing',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when there is no composer and no preloading is used.
     */
    public function testNoComposerNoPreload()
    {
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-composer'));
                TestCase::assertSame("OK - preload:''", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-composer')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-composer',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when there is no composer and preloading is used.
     */
    public function testNoComposerYesPreload()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-composer'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-composer')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-composer',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when there is preloading but no composer, in addition to a 'terminal'
     * autoloader, meaning that the autoloader fails if the class is not found.
     */
    public function testNoComposerYesPreloadAutoloadFailing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));

        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/no-composer-autoload-fails'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /no-composer-autoload-fails')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/no-composer-autoload-fails',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Simulates an autoloading scenario when there is preloading + composer in addition to a 'terminal' autoloader,
     * meaning that the autoloader fails if the class is not found.
     */
    public function testYesComposerYesPreloadAutoloadFailing()
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('opcache.preload is not available before PHP 7.4');
        }
        $this->assertFalse(file_exists($this->getPreloadTouchFilePath()));

        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/composer-autoload-fails'));
                TestCase::assertSame("OK - preload:'DDTrace classes NOT used in preload'", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
                'zend_extension' => 'opcache.so',
                'opcache.preload' => __DIR__ . '/app/preload.no.ddtrace.php',
            ]
        );

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('web.request', 'web.request', 'web', 'GET /composer-autoload-fails')
                ->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://127.0.0.1:' . self::$webserverPort . '/composer-autoload-fails',
                    'http.status_code' => '200',
                ]),
        ]);
    }

    /**
     * Given a path to a composer working directory, this method runs composer update in it.
     *
     * @param string $workingDir
     */
    private static function composerUpdateScenario($workingDir)
    {
        exec(
            "composer --working-dir='$workingDir' update -q",
            $output,
            $return
        );
        if (0 !== $return) {
            self::fail('Error while preparing the env: ' . implode("", $output));
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
