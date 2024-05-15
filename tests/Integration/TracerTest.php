<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Util\Versions;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        self::putenv('DD_TAGS=global_tag:global,also_in_span:should_not_override');
        \dd_trace_internal_fn('ddtrace_reload_config'); // tags are now internal config
    }

    protected function ddTearDown()
    {
        \putenv('DD_TAGS');
        self::putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED');
        self::putenv('DD_SERVICE_MAPPING');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
        parent::ddTearDown();
    }

    public function testGlobalTagsArePresentOnLegacySpansByFlushTime()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    'local_tag' => 'local',
                    'also_in_span' => 'span_wins',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $this->assertSame('custom.name', $traces[0][0]['name']);
        $this->assertSame('local', $traces[0][0]['meta']['local_tag']);
        $this->assertSame('global', $traces[0][0]['meta']['global_tag']);
        $this->assertSame('span_wins', $traces[0][0]['meta']['also_in_span']);
    }

    public function testGlobalTagsArePresentOnInternalSpansByFlushTime()
    {
        \DDTrace\trace_method(
            'DDTrace\Tests\Integration\TracerTest',
            'dummyMethodGlobalTags',
            function (SpanData $span) {
                $span->service = 'custom.service';
                $span->name = 'custom.name';
                $span->resource = 'custom.resource';
                $span->type = 'custom';
                $span->meta['local_tag'] = 'local';
                $span->meta['also_in_span'] = 'span_wins';
            }
        );

        $test = $this;
        $traces = $this->isolateTracer(function (Tracer $tracer) use ($test) {
            $test->dummyMethodGlobalTags();
        });

        $this->assertSame('custom.name', $traces[0][0]['name']);
        $this->assertSame('local', $traces[0][0]['meta']['local_tag']);
        $this->assertSame('global', $traces[0][0]['meta']['global_tag']);
        $this->assertSame('span_wins', $traces[0][0]['meta']['also_in_span']);
    }

    /**
     * When resource is set through tracer's $config object, it should be honored for CLI
     */
    public function testResourceNormalizationCLILegacyApiExplicitViaOptionsDefault()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.operation');
            $scope->close();
        }, null, ['resource' => 'explicit']);

        $this->assertSame('explicit', $traces[0][0]['resource']);
    }

    /**
     * When resource is not set through tracer's $config object, it sohuld fallback to operation name for CLI
     */
    public function testResourceNormalizationCLILegacyApiImplicitDefault()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.operation');
            $scope->close();
        });

        $this->assertSame('custom.operation', $traces[0][0]['resource']);
    }

    /**
     * When request to resource is OFF, it should fallback to operation name for CLI
     */
    public function testResourceNormalizationCLILegacyApiImplicitViaRequestToResourceOFF()
    {
        self::putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.operation');
            $scope->close();
        });

        $this->assertSame('custom.operation', $traces[0][0]['resource']);
    }

    /**
     * When request to resource is ON, it should fallback to operation name for CLI
     */
    public function testResourceNormalizationCLILegacyApiImplicitViaRequestToResourceON()
    {
        self::putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=true');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.operation');
            $scope->close();
        });

        $this->assertSame('custom.operation', $traces[0][0]['resource']);
    }

    /**
     * An internal api span that is not a root span should have resource = name if resource has not been explicitly set.
     * If it was a root span, then the resource name would have been set to the uri.
     */
    public function testResourceNormalizationNonRootSpanInternalApi()
    {
        \DDTrace\trace_method(
            'DDTrace\Tests\Integration\TracerTest',
            'dummyMethodResourceNormalizationInternalApi',
            function (SpanData $span) {
                $span->service = 'custom.service';
                $span->name = 'custom.internal';
                $span->type = 'custom';
                // NOT SETTING: $span->resource = 'custom.resource';
            }
        );

        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $this->dummyMethodResourceNormalizationInternalApi();
            $scope->close();
        });

        $this->assertSame('custom.internal', $traces[0][1]['resource']);
    }

    /**
     * A legacy api span that is not a root span should have resource = name if resource has not been explicitly set.
     * If it was a root span, then the resource name would have been set to the uri.
     */
    public function testResourceNormalizationNonRootSpanLegacyApi()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scopeInternal = $tracer->startActiveSpan('custom.internal');
            $scopeInternal->close();
            $scope->close();
        });

        $this->assertSame('custom.internal', $traces[0][1]['resource']);
    }

    public function testResourceNormalizationWebDefault()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('default', '/'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('GET /', $traces[0][0]['resource']);
    }

    public function testResourceNormalizationWebON()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('ON', '/'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => true,
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('GET /', $traces[0][0]['resource']);
    }

    public function testResourceNormalizationWebOFF()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('OFF', '/'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => false,
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('web.request', $traces[0][0]['resource']);
    }

    public function testResourceNormalizationWebHonorOverride()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('override-resource', '/override-resource'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => true,
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('custom-resource', $traces[0][0]['resource']);
    }

    public function testEnvironmentIsAddedToASpan()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=my-env']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('my-env', $traces[0][0]['meta']['env']);
    }

    public function testEnvironmentIsOptional()
    {
        $this->putEnvAndReloadConfig(['DD_ENV']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->close();
        });

        $this->assertTrue(empty($traces[0][0]['meta']['env']));
    }

    public function testServiceVersionIsAddedToASpan()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('1.2.3', $traces[0][0]['meta']['version']);
    }

    public function testServiceVersionIsOptional()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->close();
        });

        $this->assertTrue(empty($traces[0][0]['meta']['version']));
    }

    public function testTracerReset()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $root = $tracer->startRootSpan('custom.root');
            $tracer->startActiveSpan('custom.internal');
            $tracer->reset();
            $root->close();
        });

        $this->assertEmpty($traces);
    }

    private function preservedHook()
    {
    }

    public function testTracerResetPreservesHooks()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            \DDTrace\trace_method('DDTrace\Tests\Integration\TracerTest', 'preservedHook', function () use (&$called) {
                ++$called;
            });
            $this->preservedHook();
            self::assertSame(1, $called);
            $tracer->reset();
            $this->preservedHook();
            self::assertSame(2, $called);
        });

        // exactly one preserved_hook
        self::assertSame(1, \count($traces));
        self::assertSame(1, \count($traces[0]));
    }

    public function testServiceMappingNoEnvMapping()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->getSpan()->setTag(Tag::SERVICE_NAME, 'original_service');
            $scope->close();
        });

        $this->assertSame('original_service', $traces[0][0]['service']);
    }

    public function testServiceMappingRootSpan()
    {
        self::putenv('DD_SERVICE_MAPPING=original_service:changed_service');
        \dd_trace_internal_fn('ddtrace_reload_config'); // service mappings are now internal config
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->getSpan()->setTag(Tag::SERVICE_NAME, 'original_service');
            $scope->close();
        });

        $this->assertSame('changed_service', $traces[0][0]['service']);
    }

    public function testServiceMappingNestedSpanLegacyApi()
    {
        self::putenv('DD_SERVICE_MAPPING=original_service:changed_service');
        \dd_trace_internal_fn('ddtrace_reload_config'); // service mappings are now internal config

        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.root');
            $scope->getSpan()->setTag(Tag::SERVICE_NAME, 'root_service');
            $scopeInternal = $tracer->startActiveSpan('custom.internal');
            $scopeInternal->getSpan()->setTag(Tag::SERVICE_NAME, 'original_service');
            $scopeInternal->close();
            $scope->close();
        });

        $this->assertSame('root_service', $traces[0][0]['service']);
        $this->assertSame('changed_service', $traces[0][1]['service']);
    }

    public function testServiceMappingInternalApi()
    {
        self::putenv('DD_SERVICE_MAPPING=original_service:changed_service');
        \dd_trace_internal_fn('ddtrace_reload_config'); // service mappings are now internal config

        \DDTrace\trace_method(
            'DDTrace\Tests\Integration\TracerTest',
            'dummyMethodServiceMappingInternalApi',
            function (SpanData $span) {
                $span->service = 'original_service';
                $span->name = 'custom.name';
                $span->resource = 'custom.resource';
                $span->type = 'custom';
            }
        );

        $test = $this;
        $traces = $this->isolateTracer(function () use ($test) {
            $test->dummyMethodServiceMappingInternalApi();
        });

        $this->assertSame('changed_service', $traces[0][0]['service']);
    }

    public function testServiceMappingHttpClientsSplitByDomainHost()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('split by domain', '/curl-host'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_SERVICE_MAPPING' => 'host-httpbin_integration:changed_service',
                'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN' => true,
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('changed_service', $traces[0][1]['service']);
    }

    public function testServiceMappingHttpClientsSplitByDomainIp()
    {
        $traces = $this->inWebServer(
            function ($execute) {
                $execute(GetSpec::create('split by domain', '/curl-ip'));
            },
            __DIR__ . '/TracerTest_files/index.php',
            [
                'DD_SERVICE_MAPPING' => 'host-127.0.0.1:changed_service',
                'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN' => true,
                'DD_TRACE_NO_AUTOLOADER' => true,
                'DD_TRACE_GENERATE_ROOT_SPAN' => true,
            ]
        );

        $this->assertSame('changed_service', $traces[0][1]['service']);
    }

    public function testBaseService()
    {
        self::putEnv('DD_SERVICE=original_service');

        $traces = $this->isolateTracer(function (Tracer $tracer) {
            \DDTrace\start_span();
            $child = \DDTrace\start_span();
            $child->service = 'changed_child';
            \DDTrace\close_span();
            \DDTrace\close_span();
        });

        $this->assertSame('changed_child', $traces[0][1]['service']);
        $this->assertSame('original_service', $traces[0][1]['meta']['_dd.base_service']);
    }

    public function testBaseServiceWithMapping()
    {
        self::putEnv('DD_SERVICE_MAPPING=original_service:changed_service');
        self::putEnv('DD_SERVICE=original_service');

        $traces = $this->isolateTracer(function (Tracer $tracer) {
            \DDTrace\start_span();
            $child = \DDTrace\start_span();
            $child->service = 'changed_child';
            \DDTrace\close_span();
            \DDTrace\close_span();
        });

        $this->assertSame('changed_child', $traces[0][1]['service']);
        $this->assertSame('changed_service', $traces[0][1]['meta']['_dd.base_service']);
    }

    public function dummyMethodGlobalTags()
    {
    }

    public function dummyMethodResourceNormalizationInternalApi()
    {
    }

    public function dummyMethodServiceMappingInternalApi()
    {
    }

    public function noopEnvPrecedence()
    {
    }

    public function noopVersionPrecedence()
    {
    }
}
