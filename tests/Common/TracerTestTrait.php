<?php

namespace DDTrace\Tests\Common;

use DDTrace\Encoders\Json;
use DDTrace\Encoders\SpanEncoder;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\GlobalTracer;
use PHPUnit\Framework\TestCase;

trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request-replayer';

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateTracer($fn, $tracer = null)
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);

        // Checking spans belong to the proper integration
        $this->assertSpansBelongsToProperIntegration($this->readTraces($tracer));

        return $this->flushAndGetTraces($transport);
    }


    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateLimitedTracer($fn, $tracer = null)
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        putenv('DD_TRACE_SPANS_LIMIT=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);

        $traces =  $this->flushAndGetTraces($transport);

        putenv('DD_TRACE_SPANS_LIMIT');
        dd_trace_internal_fn('ddtrace_reload_config');

        return $traces;
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return array[]
     * @throws \Exception
     */
    public function simulateAgent($fn, $tracer = null)
    {
        // Clearing existing dumped file
        $this->resetRequestDumper();

        $transport = new Http(new Json(), ['endpoint' => self::$agentRequestDumperUrl]);
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        /** @var DebugTransport $transport */
        $tracer->flush();

        // Checking that spans belong to the correct integrations.
        $this->assertSpansBelongsToProperIntegration($this->readTraces($tracer));

        return $this->parseTracesFromDumpedData();
    }

    /**
     * Reset the request dumper removing all the dumped  data file.
     */
    private function resetRequestDumper()
    {
        $curl =  curl_init(self::$agentRequestDumperUrl . '/clear-dumped-data');
        curl_exec($curl);
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return array[]
     * @throws \Exception
     */
    public function tracesFromWebRequest($fn, $tracer = null)
    {
        // Clearing existing dumped file
        $this->resetRequestDumper();

        // The we server has to be configured to send traces to the provided requests dumper.
        $fn($tracer);

        return $this->parseTracesFromDumpedData();
    }

    /**
     * Parses the data dumped by the fake agent and returns the parsed traces.
     *
     * @return array
     * @throws \Exception
     */
    private function parseTracesFromDumpedData()
    {
        // Retrieving data
        $curl =  curl_init(self::$agentRequestDumperUrl . '/replay');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);
        if (!$response) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $loaded = json_decode($response, true);

        if (!isset($loaded['body'])) {
            return [];
        }

        $rawTraces = json_decode($loaded['body'], true);
        $traces = [];

        foreach ($rawTraces as $spansInTrace) {
            $spans = [];
            foreach ($spansInTrace as $rawSpan) {
                $spanContext = new SpanContext(
                    $rawSpan['trace_id'],
                    $rawSpan['span_id'],
                    isset($rawSpan['parent_id']) ? $rawSpan['parent_id'] : null
                );

                if (empty($rawSpan['resource'])) {
                    TestCase::fail(sprintf("Span '%s' has empty resource name", $rawSpan['name']));
                    return;
                }

                $span = new Span(
                    $rawSpan['name'],
                    $spanContext,
                    isset($rawSpan['service']) ? $rawSpan['service'] : null,
                    $rawSpan['resource'],
                    $rawSpan['start']
                );

                // We want to use reflection to set properties so that we do not fire
                // potentials changes in setters.
                $this->setRawPropertyFromArray($span, $rawSpan, 'operationName', 'name');
                $this->setRawPropertyFromArray($span, $rawSpan, 'service');
                $this->setRawPropertyFromArray($span, $rawSpan, 'resource');
                $this->setRawPropertyFromArray($span, $rawSpan, 'startTime', 'start');
                $this->setRawPropertyFromArray($span, $rawSpan, 'hasError', 'error', function ($value) {
                    return $value == 1 || $value == true;
                });
                $this->setRawPropertyFromArray($span, $rawSpan, 'type');
                $this->setRawPropertyFromArray($span, $rawSpan, 'duration');
                $this->setRawPropertyFromArray($span, $rawSpan, 'tags', 'meta');
                $this->setRawPropertyFromArray($span, $rawSpan, 'metrics', 'metrics');

                $spans[] = SpanEncoder::encode($span);
            }
            $traces[] = $spans;
        }
        return $traces;
    }

    /**
     * Set a property into an object from an array optionally applying a converter.
     *
     * @param $obj
     * @param array $data
     * @param string $property
     * @param string|null $field
     * @param mixed|null $converter
     */
    private function setRawPropertyFromArray($obj, array $data, $property, $field = null, $converter = null)
    {
        $field = $field ?: $property;

        if (!isset($data[$field])) {
            return;
        }

        $reflection = new \ReflectionObject($obj);
        $property = $reflection->getProperty($property);
        $convertedValue = $converter ? $converter($data[$field]) : $data[$field];
        if ($property->isPrivate() || $property->isProtected()) {
            $property->setAccessible(true);
            $property->setValue($obj, $convertedValue);
            $property->setAccessible(false);
        } else {
            $property->setValue($obj, $convertedValue);
        }
    }

    /**
     * @param \Closure $fn
     * @return array[]
     */
    public function simulateWebRequestTracer($fn)
    {
        $tracer = GlobalTracer::get();
        $transport = new DebugTransport();

        // Replacing the transport in the current tracer
        $tracerReflection = new \ReflectionObject($tracer);
        $tracerTransport = $tracerReflection->getProperty('transport');
        $tracerTransport->setAccessible(true);
        $tracerTransport->setValue($tracer, $transport);

        $fn($tracer);

        // We have to close the active span for web frameworks, this is what is typically done in
        // `register_shutdown_function`.
        // We need yet to find a strategy, though, to make sure that the `register_shutdown_function` is actually there
        // and that do not magically disappear. Here we are faking things.
        $tracer->getActiveSpan()->finish();

        return $this->flushAndGetTraces($transport);
    }

    /**
     * @param DebugTransport $transport
     * @return array[]
     */
    protected function flushAndGetTraces($transport)
    {
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        /** @var DebugTransport $transport */
        $tracer->flush();
        return $transport->getTraces();
    }

    /**
     * @param $name string
     * @param $fn
     * @return array[]
     */
    public function inTestScope($name, $fn)
    {
        return $this->isolateTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }

    /**
     * Extracts traces from a real tracer using reflection.
     *
     * @param Tracer $tracer
     * @return array
     */
    private function readTraces(Tracer $tracer)
    {
        // Extracting traces
        $tracerReflection = new \ReflectionObject($tracer);
        $tracesProperty = $tracerReflection->getProperty('traces');
        $tracesProperty->setAccessible(true);
        return $tracesProperty->getValue($tracer);
    }

    /**
     * Asserting that a Span belongs to the expected integration.
     *
     * @param array $traces
     */
    private function assertSpansBelongsToProperIntegration(array $traces)
    {
        $spanIntegrationChecker = new SpanIntegrationChecker();
        foreach ($traces as $trace) {
            foreach ($trace as $span) {
                $spanIntegrationChecker->checkIntegration($this, $span);
            }
        }
    }
}
