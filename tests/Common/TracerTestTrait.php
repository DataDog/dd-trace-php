<?php

namespace DDTrace\Tests\Common;

use DDTrace\Encoders\Json;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\GlobalTracer;


trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request_dumper';

    /**
     * Returns the file path for the fake agent that dumps all received tracer spans.
     *
     * @return string
     */
    protected function getDumpedFilePath()
    {
        return __DIR__ . '/../../.request_dumper_data/dump.json';
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return Span[][]
     */
    public function isolateTracer($fn, $tracer = null)
    {
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        return $this->flushAndGetTraces($transport);
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return Span[][]
     * @throws \Exception
     */
    public function simulateAgent($fn, $tracer = null)
    {
        // Removing previous file if it already exists.
        if (file_exists($this->getDumpedFilePath())) {
            unlink($this->getDumpedFilePath());
        }

        $transport = new Http(new Json(), null, ['endpoint' => self::$agentRequestDumperUrl]);
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        /** @var DebugTransport $transport */
        $tracer->flush();

        return $this->parseTracesFromDumpedData();
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @return Span[][]
     * @throws \Exception
     */
    public function tracesFromWebRequest($fn, $tracer = null)
    {
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
        if (!file_exists($this->getDumpedFilePath())) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $loaded = json_decode(file_get_contents($this->getDumpedFilePath()), true);
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
                $span = new Span(
                    $rawSpan['name'],
                    $spanContext,
                    $rawSpan['service'],
                    $rawSpan['resource'],
                    $rawSpan['start']
                );

                // We want to use reflection to set properties so that we do not fire
                // potentials changes in setters.
                $this->setRawPropertyFromArray($span, $rawSpan, 'operationName', 'name');
                $this->setRawPropertyFromArray($span, $rawSpan, 'service');
                $this->setRawPropertyFromArray($span, $rawSpan, 'resource');
                $this->setRawPropertyFromArray($span, $rawSpan, 'startTime', 'start');
                $this->setRawPropertyFromArray($span, $rawSpan, 'hasError', 'error', 'boolval');
                $this->setRawPropertyFromArray($span, $rawSpan, 'type');
                $this->setRawPropertyFromArray($span, $rawSpan, 'duration');
                $this->setRawPropertyFromArray($span, $rawSpan, 'tags', 'meta');

                $spans[] = $span;
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
        $convertedValue = $converter ? call_user_func($converter, $data[$field]) : $data[$field];
        if ($property->isPrivate() || $property->isProtected()) {
            $property->setAccessible(true);
            $property->setValue($obj, $convertedValue);
            $property->setAccessible(false);
        } else {
            $property->setValue($obj, $convertedValue);
        }
    }

    /**
     * @param $fn
     * @return Span[][]
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
     * @return Span[][]
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
     * @return Span[][]
     */
    public function inTestScope($name, $fn)
    {
        return $this->isolateTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }
}
