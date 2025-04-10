<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tracer;
use Exception;
use DDTrace\Tests\Common\BaseTestCase;

final class SpanTest extends BaseTestCase
{
    const OPERATION_NAME = 'test_span';
    const SERVICE = 'test_service';
    const RESOURCE = 'test_resource';
    const ANOTHER_NAME = 'test_span2';
    const ANOTHER_SERVICE = 'test_service2';
    const ANOTHER_RESOURCE = 'test_resource2';
    const ANOTHER_TYPE = 'test_type2';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';
    const EXCEPTION_MESSAGE = 'exception message';
    const DUMMY_STACK_TRACE = 'dummy stack trace';

    /**
     * @var Tracer|MockInterface
     */
    private $tracer;


    /**
     * @var Tracer
     */
    private $oldTracer;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->tracer = new Tracer();
        $this->oldTracer = \DDTrace\GlobalTracer::get();
        \DDTrace\GlobalTracer::set($this->tracer);
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddTearDown()
    {
        \DDTrace\GlobalTracer::set($this->oldTracer);
        dd_trace_serialize_closed_spans();
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
    }

    public function testCreateSpanSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(self::TAG_KEY, self::TAG_VALUE);

        $this->assertSame(self::OPERATION_NAME, $span->getOperationName());
        $this->assertSame(self::SERVICE, $span->getService());
        $this->assertSame(self::RESOURCE, $span->getResource());
        $this->assertSame(self::TAG_VALUE, $span->getTag(self::TAG_KEY));
    }

    public function testOverwriteOperationNameSuccess()
    {
        $span = $this->createSpan();
        $span->overwriteOperationName(self::ANOTHER_NAME);
        $this->assertSame(self::ANOTHER_NAME, $span->getOperationName());
    }

    public function testSpanTagsRemainImmutableAfterFinishing()
    {
        $span = $this->createSpan(true);
        $span->finish();

        $span->setTag(self::TAG_KEY, self::TAG_VALUE);
        $this->assertNull($span->getTag(self::TAG_KEY));
    }

    public function testSpanTagWithErrorCreatesExpectedTags()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::ERROR, new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
    }

    public function testSpanTagWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->setTag(Tag::ERROR, true);
        $this->assertTrue($span->hasError());

        $span->setTag(Tag::ERROR, false);
        $this->assertFalse($span->hasError());
    }

    public function testSpanTagWithObjectIsIgnored()
    {
        $span = $this->createSpan();
        $span->setTag('foo', new \stdClass());

        $this->assertNull($span->getTag('foo'));
    }

    public function testLogWithErrorBoolProperlyMarksError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_ERROR => true]);
        $this->assertTrue($span->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->hasError());
    }

    public function testLogWithEventErrorMarksSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'error']);
        $this->assertTrue($span->hasError());
    }

    public function testLogWithOtherEventDoesNotMarkSpanWithError()
    {
        $span = $this->createSpan();

        $span->log([Tag::LOG_EVENT => 'some other event']);
        $this->assertFalse($span->hasError());

        $span->log([Tag::LOG_ERROR => false]);
        $this->assertFalse($span->hasError());
    }


    public function testSpanLogWithErrorCreatesExpectedTags()
    {
        foreach ([Tag::LOG_ERROR, Tag::LOG_ERROR_OBJECT] as $key) {
            $span = $this->createSpan();
            $span->log([$key => new Exception(self::EXCEPTION_MESSAGE)]);

            $this->assertTrue($span->hasError());
            $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
            $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
        }
    }

    public function testSpanLogStackAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_STACK => self::DUMMY_STACK_TRACE]);

        $this->assertFalse($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_STACK), self::DUMMY_STACK_TRACE);
    }

    public function testSpanLogMessageAddsExpectedTag()
    {
        $span = $this->createSpan();
        $span->log([Tag::LOG_MESSAGE => self::EXCEPTION_MESSAGE]);

        $this->assertFalse($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
    }

    public function testSpanErrorAddsExpectedTags()
    {
        $span = $this->createSpan();
        $span->setError(new Exception(self::EXCEPTION_MESSAGE));

        $this->assertTrue($span->hasError());
        $this->assertEquals($span->getTag(Tag::ERROR_MSG), self::EXCEPTION_MESSAGE);
        $this->assertNotEmpty($span->getTag(Tag::ERROR_STACK));
        $this->assertEquals($span->getTag(Tag::ERROR_TYPE), 'Exception');
    }

    public function testSpanErrorRemainsMutableAfterFinishing()
    {
        $span = $this->createSpan(true);
        $span->finish();

        $span->setError(new Exception());
        $this->assertTrue($span->hasError());
    }

    public function testSpanSetResource()
    {
        $span = $this->createSpan();
        $span->setResource('modified_test_resource');

        $this->assertSame('modified_test_resource', $span->getResource());
    }

    public function testSpanErrorFailsForInvalidError()
    {
        $this->setExpectedException(
            '\DDTrace\Exceptions\InvalidSpanArgument',
            'Error should be either Exception or Throwable, got integer.'
        );
        $span = $this->createSpan();
        $span->setError(1);
    }

    public function testAddCustomTagsSuccess()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::SERVICE_NAME, self::ANOTHER_SERVICE);
        $span->setTag(Tag::RESOURCE_NAME, self::ANOTHER_RESOURCE);
        $span->setTag(Tag::SPAN_TYPE, self::ANOTHER_TYPE);

        $this->assertEquals(self::ANOTHER_SERVICE, $span->getService());
        $this->assertEquals(self::ANOTHER_RESOURCE, $span->getResource());
        $this->assertEquals(self::ANOTHER_TYPE, $span->getType());
    }

    public function testAddTagsFailsForInvalidTagKey()
    {
        $this->setExpectedException(
            '\DDTrace\Exceptions\InvalidSpanArgument',
            'Invalid key type in given span tags. Expected string, got integer.'
        );
        $span = $this->createSpan();
        $span->setTag(1, self::TAG_VALUE);
    }

    public function testHttpUrlIsSanitizedInTag()
    {
        $span = $this->createSpan();
        $url = 'https://example.com/some/path/index.php?some=param&other=param';

        $span->setTag(Tag::HTTP_URL, $url);
        $this->assertSame('https://example.com/some/path/index.php', $span->getAllTags()[Tag::HTTP_URL]);
    }

    public function testForceTracingTagKeepsTrace()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::MANUAL_KEEP, null);
        $this->assertSame(PrioritySampling::USER_KEEP, $this->tracer->getPrioritySampling());
    }

    public function testForceDropTracingTagRejectsTrace()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::MANUAL_DROP, null);
        $this->assertSame(PrioritySampling::USER_REJECT, $this->tracer->getPrioritySampling());

        // reset default
        \DDTrace\set_priority_sampling(\DD_TRACE_PRIORITY_SAMPLING_UNKNOWN);
    }

    public function testHasTag()
    {
        $span = $this->createSpan();
        $span->setTag('exists', 'yes');

        $this->assertTrue($span->hasTag('exists'));
        $this->assertFalse($span->hasTag('other'));
    }

    public function testMetricsSetGet()
    {
        $span = $this->createSpan();
        $span->setMetric('exists', 1.0);

        $this->assertSame(1.0, $span->getMetrics()['exists']);
    }

    public function testTraceAnalyticsConfigEnabledByTag()
    {
        $span = $this->createSpan();
        $span->setTag(Tag::ANALYTICS_KEY, 0.5);

        $this->assertSame(0.5, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testTraceAnalyticsConfigEnabledByMetric()
    {
        $span = $this->createSpan();
        $span->setMetric(Tag::ANALYTICS_KEY, 0.5);

        $this->assertSame(0.5, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testTraceAnalyticsConfigEnabledTrueResultTo1()
    {
        $span = $this->createSpan();
        $span->setMetric(Tag::ANALYTICS_KEY, true);

        $this->assertSame(1.0, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testTraceAnalyticsConfigDisabled()
    {
        $span = $this->createSpan();
        $span->setMetric(Tag::ANALYTICS_KEY, true);
        $this->assertSame(1.0, $span->getMetrics()[Tag::ANALYTICS_KEY]);

        $span->setMetric(Tag::ANALYTICS_KEY, false);
        $this->assertArrayNotHasKey(Tag::ANALYTICS_KEY, $span->getMetrics());
    }

    public function testTraceAnalyticsConfigSpecificRate()
    {
        $span = $this->createSpan();
        $span->setMetric(Tag::ANALYTICS_KEY, 0.3);
        $this->assertSame(0.3, $span->getMetrics()[Tag::ANALYTICS_KEY]);
    }

    public function testSpanCreationDoesNotInterfereWithDeterministicRandomness()
    {
        // Ensures old mt_rand() behavior before PHP 7.1 changes
        if (PHP_VERSION >= '70100') {
            mt_srand(42, MT_RAND_PHP);
        } else {
            mt_srand(42);
        }
        mt_rand(); // The first number is different on PHP <= 7.0 for some reason...
        $randInts = [mt_rand()];
        $this->createSpan();
        $randInts[] = mt_rand();

        $this->assertSame([1710563033, 2041643438], $randInts);
    }

    public function testBaggageApi()
    {
        $span = $this->createSpan();

        // Test setting and getting a baggage
        $span->addBaggageItem('user_id', 123);
        $this->assertEquals(123, $span->getBaggageItem('user_id'));

        // Test setting multiple entries and retrieving all baggage
        $span->addBaggageItem('session_id', 'abc123');
        $expectedBaggage = [
            'user_id' => 123,
            'session_id' => 'abc123'
        ];
        $this->assertEquals($expectedBaggage, $span->getAllBaggageItems());

        // Test removing a single baggage
        $span->removeBaggageItem('user_id');
        $this->assertNull($span->getBaggageItem('user_id'));
        $this->assertEquals(['session_id' => 'abc123'], $span->getAllBaggageItems());

        // Test removing all baggage entries
        $span->removeAllBaggageItems();
        $this->assertEmpty($span->getAllBaggageItems());

        // Test overwriting an existing key
        $span->addBaggageItem('user_id', 123);
        $span->addBaggageItem('user_id', 456);
        $this->assertEquals(456, $span->getBaggageItem('user_id'));

        // Test setting empty keys (should be ignored)
        $span->addBaggageItem('', 'some_value');
        $this->assertEquals(['user_id' => 456], $span->getAllBaggageItems());

        // Test setting empty values (should still be stored)
        $span->addBaggageItem('empty_value', '');
        $this->assertEquals('', $span->getBaggageItem('empty_value'));
  
        // Test removing a non-existent key (should not cause errors)
        $span->removeBaggageItem('non_existent_key');
        $this->assertEquals(['user_id' => 456, 'empty_value' => ''], $span->getAllBaggageItems());

        // Test clearing an already empty baggage
        $span->removeAllBaggageItems();
        $span->removeAllBaggageItems();
        $this->assertEmpty($span->getAllBaggageItems());
    }

    public function testApiBaggageMergingWithHeaders()
    {
        // Step 1: Set baggage using API before a request
        $span = new Span(\DDTrace\start_trace_span(), SpanContext::createAsRoot());
        $span->addBaggageItem('user_id', '123');
        $span->addBaggageItem('serverNode', 'local');
        $span->addBaggageItem('session', 'abc123');
        $span->addBaggageItem('",;\()/:<=>?@[]{}', '",;\\');

        // Step 2: Simulate an incoming request with baggage in the headers
        $incomingHeaders = [
            'baggage' => 'user_id=999,serverNode=remote,env=production'
        ];

        // Step 3: Consume the request headers (Simulate extraction)
        \DDTrace\consume_distributed_tracing_headers(function ($header) use ($incomingHeaders) {
            return $incomingHeaders[$header] ?? null;
        });



        // Step 4: Start a new span (merging should happen here)
        $newSpan = $this->createSpan(true);
        $expectedMergedBaggage = [
            'user_id' => '999', // Overwritten by request header
            'serverNode' => 'remote', // Overwritten by request header
            'session' => 'abc123', // Preserved from API-set baggage
            '",;\()/:<=>?@[]{}' => '",;\\', // Preserved from API-set baggage
            'env' => 'production' // New from request header
        ];
        $this->assertEquals($expectedMergedBaggage, $newSpan->getAllBaggageItems());
    }

    public function testActiveTraceBaggagePropagation()
    {
        // Step 1: Start Trace and add some baggage
        $span_1 = new Span(\DDTrace\start_trace_span(), SpanContext::createAsRoot());
        $span_1->addBaggageItem('user_id', '123');
        $span_1->addBaggageItem('serverNode', 'local');
        $span_1->addBaggageItem('session', 'abc123');

        // Step 2: Start second trace and confirm baggage is inherited
        $span_2 = new Span(\DDTrace\start_trace_span(), SpanContext::createAsRoot());

        $this->assertEquals($span_1->getAllBaggageItems(), $span_2->getAllBaggageItems());
    }

    private function createSpan($realSpan = false)
    {
        $context = SpanContext::createAsRoot();

        $internalSpan = $realSpan ? \DDTrace\start_span() : new SpanData();
        $internalSpan->name = self::OPERATION_NAME;
        $internalSpan->service = self::SERVICE;
        $internalSpan->resource = self::RESOURCE;
        $span = new Span($internalSpan, $context);

        return $span;
    }
}
