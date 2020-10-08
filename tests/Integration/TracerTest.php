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

    protected function setUp()
    {
        parent::setUp();
        \putenv('DD_TAGS=global_tag:global,also_in_span:should_not_ovverride');
    }

    protected function tearDown()
    {
        \putenv('DD_TAGS');
        \putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED');
        \putenv('DD_SERVICE_MAPPING');
        parent::tearDown();
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
            $scope = $tracer->startActiveSpan('custom.operation');
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
            $scope = $tracer->startActiveSpan('custom.operation');
            $scope->close();
        });

        $this->assertSame('custom.operation', $traces[0][0]['resource']);
    }

    /**
     * When request to resource is OFF, it should fallback to operation name for CLI
     */
    public function testResourceNormalizationCLILegacyApiImplicitViaRequestToResourceOFF()
    {
        putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=false');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.operation');
            $scope->close();
        });

        $this->assertSame('custom.operation', $traces[0][0]['resource']);
    }

    /**
     * When request to resource is ON, it should fallback to operation name for CLI
     */
    public function testResourceNormalizationCLILegacyApiImplicitViaRequestToResourceON()
    {
        putenv('DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=true');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.operation');
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
            $scope = $tracer->startActiveSpan('custom.root');
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
            $scope = $tracer->startActiveSpan('custom.root');
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
            ]
        );

        $this->assertSame('custom-resource', $traces[0][0]['resource']);
    }

    public function testEnvironmentIsAddedToASpan()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=my-env']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('my-env', $traces[0][0]['meta']['env']);
    }

    public function testEnvironmentIsOptional()
    {
        $this->putEnvAndReloadConfig(['DD_ENV']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertTrue(empty($traces[0][0]['meta']['env']));
    }

    public function testDDEnvHasPrecedenceOverGlobalTags()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=from-env', 'DD_TAGS=env:from-tags']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('from-env', $traces[0][0]['meta']['env']);
    }

    public function testDDEnvHasPrecedenceOverGlobalTagsForChildrenSpans()
    {
        \DDTrace\trace_method(
            'DDTrace\Tests\Integration\TracerTest',
            'noopEnvPrecedence',
            function (SpanData $span) {
                $span->name = 'custom.child.2';
                $span->meta = ['local_tag' => 'local'];
            }
        );

        $this->putEnvAndReloadConfig(['DD_ENV=from-env', 'DD_TAGS=env:from-tags,global:foo']);

        $test = $this;
        $traces = $this->isolateTracer(function (Tracer $tracer) use ($test) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scopeChild = $tracer->startActiveSpan('custom.child.1');
            $test->noopEnvPrecedence();
            $scopeChild->close();
            $scope->close();
        });

        self::assertCount(3, $traces[0]);

        $span = $traces[0][0];
        self::assertSame('custom.root', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('from-env', $span['meta']['env']);

        $span = $traces[0][1];
        self::assertSame('custom.child.1', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('from-env', $span['meta']['env']);

        $span = $traces[0][2];
        self::assertSame('custom.child.2', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('local', $span['meta']['local_tag']);
        self::assertSame('from-env', $span['meta']['env']);
    }

    public function testServiceVersionIsAddedToASpan()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=1.2.3']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('1.2.3', $traces[0][0]['meta']['version']);
    }

    public function testServiceVersionIsOptional()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertTrue(empty($traces[0][0]['meta']['version']));
    }

    public function testDDVersionHasPrecedenceOverGlobalTags()
    {
        $this->putEnvAndReloadConfig(['DD_VERSION=from-env', 'DD_TAGS=version:from-tags']);
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->close();
        });

        $this->assertSame('from-env', $traces[0][0]['meta']['version']);
    }

    public function testDDVersionPrecedenceOverGlobalTagsForChildrenSpans()
    {
        \DDTrace\trace_method(
            'DDTrace\Tests\Integration\TracerTest',
            'noopVersionPrecedence',
            function (SpanData $span) {
                $span->name = 'custom.child.2';
            }
        );

        $this->putEnvAndReloadConfig(['DD_VERSION=from-env', 'DD_TAGS=version:from-tags,global:foo']);

        $test = $this;
        $traces = $this->isolateTracer(function (Tracer $tracer) use ($test) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scopeChild = $tracer->startActiveSpan('custom.child.1');
            $test->noopVersionPrecedence();
            $scopeChild->close();
            $scope->close();
        });

        self::assertCount(3, $traces[0]);

        $span = $traces[0][0];
        self::assertSame('custom.root', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('from-env', $span['meta']['version']);

        $span = $traces[0][1];
        self::assertSame('custom.child.1', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('from-env', $span['meta']['version']);

        $span = $traces[0][2];
        self::assertSame('custom.child.2', $span['name']);
        self::assertSame('foo', $span['meta']['global']);
        self::assertSame('from-env', $span['meta']['version']);
    }

    public function testServiceMappingNoEnvMapping()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->getSpan()->setTag(Tag::SERVICE_NAME, 'original_service');
            $scope->close();
        });

        $this->assertSame('original_service', $traces[0][0]['service']);
    }

    public function testServiceMappingRootSpan()
    {
        putenv('DD_SERVICE_MAPPING=original_service:changed_service');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
            $scope->getSpan()->setTag(Tag::SERVICE_NAME, 'original_service');
            $scope->close();
        });

        $this->assertSame('changed_service', $traces[0][0]['service']);
    }

    public function testServiceMappingNestedSpanLegacyApi()
    {
        putenv('DD_SERVICE_MAPPING=original_service:changed_service');
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startActiveSpan('custom.root');
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
        putenv('DD_SERVICE_MAPPING=original_service:changed_service');

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
            ]
        );

        $this->assertSame('changed_service', $traces[0][1]['service']);
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
