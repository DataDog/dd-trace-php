<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\SpanData;
use DDTrace\Util\Versions;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait;

    protected function setUp()
    {
        parent::setUp();
        \putenv('DD_TRACE_GLOBAL_TAGS=global_tag:global,also_in_span:should_not_ovverride');
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
        if (Versions::phpVersionMatches('5.4')) {
            $this->markTestSkipped('Internal spans are not enabled yet on PHP 5.4');
        }
        \dd_trace_method('DDTrace\Tests\Integration\TracerTest', 'dummyMethod', function (SpanData $span) {
            $span->service = 'custom.service';
            $span->name = 'custom.name';
            $span->resource = 'custom.resource';
            $span->type = 'custom';
            $span->meta['local_tag'] = 'local';
            $span->meta['also_in_span'] = 'span_wins';
        });

        $test = $this;
        $traces = $this->isolateTracer(function (Tracer $tracer) use ($test) {
            $test->dummyMethod();
        });

        $this->assertSame('custom.name', $traces[0][0]['name']);
        $this->assertSame('local', $traces[0][0]['meta']['local_tag']);
        $this->assertSame('global', $traces[0][0]['meta']['global_tag']);
        $this->assertSame('span_wins', $traces[0][0]['meta']['also_in_span']);
    }

    public function dummyMethod()
    {
    }
}
