<?php

namespace DDTrace\Tests\Common;

use DDTrace\Encoders\MessagePack;
use DDTrace\Encoders\SpanEncoder;
use DDTrace\GlobalTracer;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\SpanData;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use Exception;
use PHPUnit\Framework\TestCase;

if (PHP_VERSION_ID >= 80000) {
    class FakeSpan extends Span
    {
        public $startTime;
        public $duration;
    }
}

trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request-replayer';

    public function resetTracer($tracer = null, $config = [])
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport, null, $config);
        GlobalTracer::set($tracer);
        return $transport;
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateTracer($fn, $tracer = null, $config = [])
    {
        $transport = $this->resetTracer($tracer, $config);

        $tracer = GlobalTracer::get();
        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            $tracer->startRootSpan("root span");
        }
        $fn($tracer);

        return $this->flushAndGetTraces($transport);
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function inRootSpan($fn, $tracer = null)
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $scope = $tracer->startRootSpan('root_span');
        $fn($tracer);
        $scope->close();

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
        putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);

        $traces = $this->flushAndGetTraces($transport);

        putenv('DD_TRACE_SPANS_LIMIT');
        putenv('DD_TRACE_GENERATE_ROOT_SPAN');
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

        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();

        $transport = new Http(new MessagePack(), ['endpoint' => self::$agentRequestDumperUrl]);

        /* Disable Expect: 100-Continue that automatically gets added by curl,
         * as it adds a 1s delay, causing tests to sometimes fail.
         */
        $transport->setHeader('Expect', '');

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
     * This method executes a request into an ad-hoc web server configured with the provided envs and inis that is
     * created and destroyed with the scope of this test.
     */
    public function inWebServer($fn, $rootPath, $envs = [], $inis = [], &$curlInfo = null)
    {
        $this->resetTracer();
        $this->resetRequestDumper();
        $webServer = new WebServer($rootPath, '0.0.0.0', 6666);
        $webServer->mergeEnvs($envs);
        $webServer->mergeInis($inis);
        $webServer->start();

        $fn(function (RequestSpec $request) use ($webServer, &$curlInfo) {
            if ($request instanceof GetSpec) {
                $curl = curl_init('http://127.0.0.1:6666' . $request->getPath());
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $request->getHeaders());
                $response = curl_exec($curl);
                if (\is_array($curlInfo)) {
                    $curlInfo = \array_merge($curlInfo, \curl_getinfo($curl));
                }
                \curl_close($curl);
                $webServer->stop();
                return $response;
            }

            $webServer->stop();
            throw new Exception('Spec type not supported.');
        });

        return $this->parseTracesFromDumpedData();
    }

    /**
     * This method executes a single script with the provided configuration.
     */
    public function inCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '')
    {
        $this->resetRequestDumper();
        $this->executeCli($scriptPath, $customEnvs, $customInis, $arguments);
        return $this->parseTracesFromDumpedData();
    }

    public function executeCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '')
    {
        $envs = (string) new EnvSerializer(array_merge(
            [
                'DD_AUTOLOAD_NO_COMPILE' => getenv('DD_AUTOLOAD_NO_COMPILE'),
                'DD_TRACE_CLI_ENABLED' => 'true',
                'DD_AGENT_HOST' => 'request-replayer',
                'DD_TRACE_AGENT_PORT' => '80',
                // Uncomment to see debug-level messages
                //'DD_TRACE_DEBUG' => 'true',
            ],
            $customEnvs
        ));
        $inis = (string) new IniSerializer(array_merge(
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ],
            $customInis
        ));

        $script = escapeshellarg($scriptPath);
        $arguments = escapeshellarg($arguments);
        $commandToExecute = "$envs php $inis $script $arguments";
        `$commandToExecute`;
    }

    /**
     * Reset the request dumper removing all the dumped  data file.
     */
    public function resetRequestDumper()
    {
        $curl = curl_init(self::$agentRequestDumperUrl . '/clear-dumped-data');
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
        if ($tracer === null) {
            // Avoid phpunits default spans from being acknowledged for distributed tracing
            $this->resetTracer();
        }

        // Clearing existing dumped file
        $this->resetRequestDumper();

        // The we server has to be configured to send traces to the provided requests dumper.
        $fn($tracer);

        return $this->parseTracesFromDumpedData();
    }

    private function parseRawDumpedTraces($rawTraces)
    {
        $traces = [];

        foreach ($rawTraces as $spansInTrace) {
            $spans = [];
            foreach ($spansInTrace as $rawSpan) {
                $spanContext = new SpanContext(
                    (string) $rawSpan['trace_id'],
                    (string) $rawSpan['span_id'],
                    isset($rawSpan['parent_id']) ? (string) $rawSpan['parent_id'] : null
                );

                if (empty($rawSpan['resource'])) {
                    TestCase::fail(sprintf("Span '%s' has empty resource name", $rawSpan['name']));
                    return;
                }


                if (PHP_VERSION_ID < 80000) {
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
                    $this->setRawPropertyFromArray($span, $rawSpan, 'type');
                    $this->setRawPropertyFromArray($span, $rawSpan, 'duration');
                    $this->setRawPropertyFromArray($span, $rawSpan, 'tags', 'meta');
                    $this->setRawPropertyFromArray($span, $rawSpan, 'metrics', 'metrics');
                } else {
                    $internalSpan = new SpanData();
                    $internalSpan->name = $rawSpan["name"];
                    $internalSpan->service = isset($rawSpan['service']) ? $rawSpan['service'] : null;
                    $internalSpan->resource = $rawSpan['resource'];
                    if (isset($rawSpan['type'])) {
                        $internalSpan->type = $rawSpan['type'];
                    }
                    $internalSpan->meta = isset($rawSpan['meta']) ? $rawSpan['meta'] : [];
                    $internalSpan->metrics = isset($rawSpan['metrics']) ? $rawSpan['metrics'] : [];
                    $span = new FakeSpan($internalSpan, $spanContext);
                    $span->duration = $rawSpan["duration"] / 1000;
                    $span->startTime = $rawSpan["start"] / 1000;
                }
                $this->setRawPropertyFromArray($span, $rawSpan, 'hasError', 'error', function ($value) {
                    return $value == 1 || $value == true;
                });

                $spans[] = SpanEncoder::encode($span);
            }
            $traces[] = $spans;
        }

        return $traces;
    }

    /**
     * Parses the data dumped by the fake agent and returns the parsed traces.
     *
     * @return array
     * @throws \Exception
     */
    private function parseTracesFromDumpedData()
    {
        $response = $this->retrieveDumpedData();

        if (!$response) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $loaded = json_decode($response, true);

        // Data is returned as [{trace_1}, {trace_2}]. As of today we only support parsing 1 trace.
        if (count($loaded) > 1) {
            TestCase::fail(
                sprintf("Received multiple bodys from request replayer: %s", \var_export($loaded, true))
            );
        }

        $uniqueRequest = $loaded[0];

        if (!isset($uniqueRequest['body'])) {
            return [];
        }

        $rawTraces = json_decode($uniqueRequest['body'], true);
        return $this->parseRawDumpedTraces($rawTraces);
    }

    public function parseMultipleRequestsFromDumpedData()
    {
        $response = $this->retrieveDumpedData();
        if (!$response) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $manyRequests = json_decode($response, true);
        $tracesAllRequests = [];

        // We receive back an array of traces
        foreach ($manyRequests as $uniqueRequest) {
            // error_log('Request: ' . print_r($uniqueRequest, 1));
            $rawTraces = json_decode($uniqueRequest['body'], true);
            $tracesAllRequests[] = $this->parseRawDumpedTraces($rawTraces);
        }

        // We need to handle potential empty flushes (without internal flushing)...
        return PHP_VERSION_ID >= 80000 ? $tracesAllRequests : array_values(array_filter($tracesAllRequests));
    }

    /**
     * Returns the raw response body, if any, or null otherwise.
     */
    private function retrieveDumpedData()
    {
        $response = null;
        // When tests run with the background sender enabled, there might be some delay between when a trace is flushed
        // and actually sent. While we should find a smart way to tackle this, for now we do it quick and dirty, in a
        // for loop.
        for ($attemptNumber = 1; $attemptNumber <= 20; $attemptNumber++) {
            $curl = curl_init(self::$agentRequestDumperUrl . '/replay');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            // Retrieving data
            $response = curl_exec($curl);
            if (!$response) {
                // PHP-FPM requests are much slower in the container
                // Temporary workaround until we get a proper test runner
                \usleep(
                    'fpm-fcgi' === \getenv('DD_TRACE_TEST_SAPI')
                        ? 500 * 1000 // 500 ms for PHP-FPM
                        : 50 * 1000 // 50 ms for other SAPIs
                );
                continue;
            } else {
                break;
            }
        }
        return $response;
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
        if (PHP_VERSION_ID < 80000) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            /** @var DebugTransport $transport */
            $tracer->flush();
            return $transport->getTraces();
        }
        $traces = \dd_trace_serialize_closed_spans();
        return $traces ? [$traces] : [];
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
}
