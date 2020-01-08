<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\SpanData;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait;

    protected function setUp()
    {
        parent::setUp();
        \putenv('DD_TRACE_GLOBAL_TAGS=global_tag:global');
    }

    protected function tearUp()
    {
        \putenv('DD_TRACE_GLOBAL_TAGS');
        parent::tearDown();
    }

    public function testGlobalTagsArePresentOnLegacySpansByFlushTime()
    {
        $traces = $this->isolateTracer(function (Tracer $tracer) {
            $scope = $tracer->startRootSpan('custom.name', [
                'tags' => [
                    'local_tag' => 'local',
                ],
            ]);
            $scope->getSpan()->finish();
        });

        $this->assertSame('custom.name', $traces[0][0]['name']);
        $this->assertSame('local', $traces[0][0]['meta']['local_tag']);
        $this->assertSame('global', $traces[0][0]['meta']['global_tag']);
    }

    public function testGlobalTagsArePresentOnInternalSpansByFlushTime()
    {
        \dd_trace_method('DDTrace\Tests\Integration\TracerTest', 'dummyMethod', function (SpanData $span) {
            $span->service = 'custom.service';
            $span->name = 'custom.name';
            $span->resource = 'custom.resource';
            $span->type = 'custom';
            $span->meta['local_tag'] = 'local';
        });

        $test = $this;
        $traces = $this->isolateTracer(function (Tracer $tracer) use ($test) {
            $test->dummyMethod();
        });

        $this->assertSame('custom.name', $traces[0][0]['name']);
        $this->assertSame('local', $traces[0][0]['meta']['local_tag']);
        $this->assertSame('global', $traces[0][0]['meta']['global_tag']);
    }

    public function dummyMethod()
    {
    }
}
