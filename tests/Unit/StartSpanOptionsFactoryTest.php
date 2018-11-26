<?php

namespace DDTrace\Tests\Unit;

use DDTrace\SpanContext;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tracer;
use Mockery\MockInterface;
use OpenTracing\Reference;


final class StartSpanOptionsFactoryTest extends BaseTestCase
{
    /**
     * @var Tracer|MockInterface
     */
    private $tracer;

    protected function setUp()
    {
        parent::setUp();
        $this->tracer = \Mockery::mock('OpenTracing\Tracer');
    }

    public function test_createForWebRequest_noExtractedContext()
    {
        $this->tracer->shouldReceive('extract')->andReturnNull();
        $startSpanOptions = StartSpanOptionsFactory::createForWebRequest($this->tracer);

        $this->assertEmpty($startSpanOptions->getReferences());
    }

    public function test_createForWebRequest_extractedContext()
    {
        $spanContext = new SpanContext('trace_id', 'span_id');
        $this->tracer->shouldReceive('extract')->andReturn($spanContext);
        $startSpanOptions = StartSpanOptionsFactory::createForWebRequest($this->tracer);
        /** @var Reference[] $references */
        $references = $startSpanOptions->getReferences();

        $this->assertTrue($references[0]->isType('child_of'));
        $this->assertSame($spanContext, $references[0]->getContext());
    }

    public function test_createForWebRequest_httpHeadersPassedAsCarrier()
    {
        $headers = [];
        $this->tracer->shouldReceive('extract')
            ->with('http_headers', $headers)
            ->once()
            ->andReturnNull();
        $this->assertNotNull(StartSpanOptionsFactory::createForWebRequest($this->tracer));
    }
}
