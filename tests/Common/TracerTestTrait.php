<?php

namespace DDTrace\Tests\Common;

use DDTrace\GlobalTracer;
use DDTrace\HookData;
use DDTrace\SpanData;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Tests\WebServer;
use DDTrace\Tracer;
use DDTrace\Util\SpanKindNames;
use Exception;
use PHPUnit\Framework\TestCase;

trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request-replayer';
    protected static $testAgentUrl = 'http://test-agent:9126';

    protected static $webserverPort = 6666 + GLOBAL_PORT_OFFSET;

    /** @var array The payload fields (without chunks) of the trace dumps parsed since the last dumper reset */
    protected $dumpedPayload = [];

    /** Payload attributes that the v0.4 wire carries on spans instead (see libdatadog's v0.4 downgrade). */
    private static $v04PayloadAttributes = ['_dd.tags.process', '_dd.sdk.otlp_export', '_dd.git.commit.sha', '_dd.git.repository_url'];

    public function resetTracer($tracer = null, $config = [])
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $headers = $transport->getHeaders();
        $dd_header_with_env = getHeaderWithEnvironment();
        if ($dd_header_with_env) {
            $transport->setHeader("X-Datadog-Trace-Env-Variables", $dd_header_with_env);
        }
        $tracer = $tracer ?: new Tracer($transport, null, $config);
        GlobalTracer::set($tracer);
    }

    public static function setResponse($content)
    {
        $response = file_get_contents(self::$agentRequestDumperUrl . '/next-response', false, stream_context_create([
            "http" => [
                "method" => "POST",
                "content" => json_encode($content),
                "header" => [
                    "Content-Type: application/json",
                    'X-Datadog-Test-Session-Token: ' . ini_get("datadog.trace.agent_test_session_token"),
                ],
            ]
        ]));
    }

    /**
     * @param $fn
     * @param null $tracer
     * @return array[]
     */
    public function isolateTracer($fn, $tracer = null, $config = [])
    {
        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT=666666'); // Arbitrarily high value to avoid flakiness
        self::putEnv('DD_TRACE_AGENT_RETRIES=3');

        $this->resetTracer($tracer, $config);

        $tracer = GlobalTracer::get();
        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            $tracer->startRootSpan("root span");
        }
        $fn($tracer);

        $traces = $this->flushAndSendTracesToTestAgent();

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        return $traces;
    }

    /**
     * Like flushAndGetTraces(), and also sends the closed spans to the test agent, as the v0.4 msgpack
     * payloads that the in-process sender's downgrade produces.
     */
    protected function flushAndSendTracesToTestAgent()
    {
        list($payload, $v04Payloads) = \DDTrace\Testing\serialize_closed_spans_v04();
        foreach ($v04Payloads as $v04Payload) {
            $this->sendTracesToTestAgent($v04Payload);
        }
        return $payload['chunks'];
    }

    /** @param string $v04Payload A v0.4 msgpack payload with a single trace */
    public function sendTracesToTestAgent($v04Payload)
    {
        // The headers to be included in the request
        $headers = array(
            'Content-Type: application/msgpack',
            'Datadog-Meta-Lang: php',
            'X-Datadog-Agent-Proxy-Disabled: true',
            'X-Datadog-Trace-Count: 1',
            'X-Datadog-Test-Session-Token: ' . ini_get("datadog.trace.agent_test_session_token"),
        );

        // add environment variables to headers
        $dd_header_with_env = getHeaderWithEnvironment();
        if ($dd_header_with_env) {
            $headers[] = "X-Datadog-Trace-Env-Variables: " . $dd_header_with_env;
        }

        // Initialize a cURL session
        $curl = curl_init();

        // Set the cURL options
        curl_setopt($curl, CURLOPT_URL, 'http://test-agent:9126/v0.4/traces'); // The URL to send the request to
        curl_setopt($curl, CURLOPT_POST, true); // Use POST method
        curl_setopt($curl, CURLOPT_POSTFIELDS, $v04Payload); // Set the POST data
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the response instead of outputting it
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // Set the headers

        // Execute the cURL session
        $response = self::curlWithoutSpan($curl);

        // Close the cURL session
        if (PHP_VERSION_ID < 80000) { curl_close($curl); }

        // Output the response for debugging purposes
        //echo $response;
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

        return $this->flushAndGetTraces();
    }

    public static function curlWithoutSpan($curl)
    {
        $limit = ini_get("datadog.trace.spans_limit");
        ini_set("datadog.trace.spans_limit", 0);
        $ret = curl_exec($curl);
        ini_set("datadog.trace.spans_limit", $limit);
        return $ret;
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
        self::putenv('DD_TRACE_SPANS_LIMIT=0');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);

        $traces = $this->flushAndGetTraces();

        self::putenv('DD_TRACE_SPANS_LIMIT');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
        dd_trace_internal_fn('ddtrace_reload_config');

        return $traces;
    }

    /**
     * This method executes a request into an ad-hoc web server configured with the provided envs and inis that is
     * created and destroyed with the scope of this test.
     */
    public function inWebServer($fn, $rootPath, $envs = [], $inis = [], $retryOnEmptyTraces = true, &$curlInfo = null)
    {
        $retries = $retryOnEmptyTraces ? 1 : 0;
        do {
            self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT=666666'); // Arbitrarily high value to avoid flakiness
            self::putEnv('DD_TRACE_AGENT_RETRIES=3');

            $this->resetTracer();
            $webServer = new WebServer($rootPath, '0.0.0.0', self::$webserverPort);
            $webServer->mergeEnvs($envs);
            $webServer->mergeInis($inis);
            $webServer->start();
            $this->resetRequestDumper();

            $fn(function (RequestSpec $request) use ($webServer, &$curlInfo) {
                if ($request instanceof GetSpec) {
                    $curl = curl_init('http://127.0.0.1:' . self::$webserverPort . $request->getPath());
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $request->getHeaders());
                    $response = self::curlWithoutSpan($curl);
                    if (\is_array($curlInfo)) {
                        $curlInfo = \array_merge($curlInfo, \curl_getinfo($curl));
                    }
                    if (PHP_VERSION_ID < 80000) { curl_close($curl); }
                    $webServer->stop();
                    return $response;
                }

                $webServer->stop();
                throw new Exception('Spec type not supported.');
            });

            self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
            self::putEnv('DD_TRACE_AGENT_RETRIES');

            if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
                \dd_trace_synchronous_flush();
            }
            $traces = $this->parseTracesFromDumpedData();

            if ($traces || $retries-- <= 0) {
                return $traces;
            }
        } while (true);
    }

    /**
     * This method executes a single script with the provided configuration.
     */
    public function inCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '', $withOutput = false, $until = null, $throw = true, $checkTty = false)
    {
        $this->resetRequestDumper();
        $output = $this->executeCli($scriptPath, $customEnvs, $customInis, $arguments, $withOutput, false, false, $checkTty);
        usleep(100000); // Add a slight delay to give the request-replayer time to handle and store all requests.
        $out = [$this->parseTracesFromDumpedData($until, $throw)];
        if ($withOutput) {
            $out[] = $output;
        }
        return $out;
    }

    public function executeCli($scriptPath, $customEnvs = [], $customInis = [], $arguments = '', $withOutput = false, $skipSyncFlush = false, $withExitCode = false, $checkTty = false)
    {
        $envs = (string) new EnvSerializer(array_merge(
            [
                'DD_AUTOLOAD_NO_COMPILE' => getenv('DD_AUTOLOAD_NO_COMPILE'),
                'DD_AGENT_HOST' => 'test-agent',
                'DD_TRACE_AGENT_PORT' => '9126',
                // Uncomment to see debug-level messages
                //'DD_TRACE_DEBUG' => 'true',
                'DD_TRACE_SHUTDOWN_TIMEOUT' => '666666', // Arbitrarily high value to avoid flakiness
                'DD_TRACE_AGENT_RETRIES' => '3'
            ],
            $customEnvs
        ));

        if (GLOBAL_AUTO_PREPEND_FILE) {
            $customInis['auto_prepend_file'] = GLOBAL_AUTO_PREPEND_FILE;
        }
        if (getenv('PHPUNIT_COVERAGE')) {
            $xdebugExtension = glob(PHP_EXTENSION_DIR . '/xdebug*.so');
            $xdebugExtension = end($xdebugExtension);
            $customInis['zend_extension'] = $xdebugExtension;
            $customInis['xdebug.mode'] = 'coverage';
        }

        $inis = (string) new IniSerializer(array_merge(
            [
                'datadog.trace.sources_path' => __DIR__ . '/../../src',
            ],
            $customInis
        ));

        $script = escapeshellarg($scriptPath);
        if (\is_string($arguments)) {
            $arguments = escapeshellarg($arguments);
        } elseif (\is_array($arguments)) {
            $arguments = implode(' ', array_map('escapeshellarg', $arguments));
        }
        if ($checkTty && !posix_isatty(STDOUT)) {
            $commandToExecute = "script -q -c \"$envs " . PHP_BINARY . " $inis $script $arguments\" /dev/null";
        } else {
            $commandToExecute = "$envs " . PHP_BINARY . " $inis $script $arguments";
        }
        $output = [];
        $exitCode = 0;
        $createHook = \DDTrace\install_hook('DDTrace\Integrations\Exec\ExecIntegration::createSpan', function (HookData $hook) {
            $hook->disableJitInlining();
            $hook->suppressCall();
            $hook->overrideReturnValue(new SpanData);
        });
        $finishHook = \DDTrace\install_hook('DDTrace\Integrations\Exec\ExecIntegration::finishSpanRestoreStack', function (HookData $hook) {
            $hook->suppressCall();
        });
        exec($commandToExecute . ' 2>&1', $output, $exitCode);
        \DDTrace\remove_hook($createHook);
        \DDTrace\remove_hook($finishHook);
        $output = implode("\n", $output);
        if (preg_match('(\[error\]|\[warning\]|\[deprecated\])', $output)) {
            throw new \Exception("Got unexpected ddtrace warnings or errors in output:\n\n$output");
        }
        $ret = $withOutput ? $output : null;
        if (!$skipSyncFlush && \dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
            \dd_trace_synchronous_flush();
        }
        return $withExitCode ? [$ret, $exitCode] : $ret;
    }

    /**
     * Reset the request dumper removing all the dumped  data file.
     */
    public function resetRequestDumper()
    {
        $this->dumpedPayload = [];
        $curl = curl_init(self::$agentRequestDumperUrl . '/clear-dumped-data');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['x-datadog-test-session-token: ' . ini_get("datadog.trace.agent_test_session_token")]);
        self::curlWithoutSpan($curl);
    }

    /**
     * This method can be used to request data to a real request dumper and to rebuild the traces
     * based on the dumped data.
     *
     * @param $fn
     * @param null $tracer
     * @param callable|null $until
     * @return array[]
     * @throws Exception
     */
    public function tracesFromWebRequest($fn, $tracer = null, $until = null)
    {
        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT=666666'); // Arbitrarily high value to avoid flakiness
        self::putEnv('DD_TRACE_AGENT_RETRIES=3');

        if ($tracer === null) {
            // Avoid phpunits default spans from being acknowledged for distributed tracing
            $this->resetTracer();
        }

        // Clearing existing dumped file
        $this->resetRequestDumper();

        // The web server has to be configured to send traces to the provided requests dumper.
        $fn($tracer);

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        return $this->parseTracesFromDumpedData($until);
    }

    /**
     * Parses a dumped trace payload into the chunks of the V1 view (as dd_trace_serialize_closed_spans()
     * returns them), whatever the wire: the replayer's JSON view of the v1 payload, or a v0.4 (list of
     * traces) / v0.7 (chunks of v0.4 spans) payload.
     */
    protected function parseRawDumpedTraces($rawTraces)
    {
        $chunks = [];
        if (!$rawTraces || isset($rawTraces[0])) {
            $chunks = array_map([$this, 'fromV04Trace'], $rawTraces ?: []);
        } elseif (isset($rawTraces['chunks'])) {
            $payload = $rawTraces;
            unset($payload['chunks']);
            $this->dumpedPayload = array_replace_recursive($this->dumpedPayload, $payload);
            foreach ($rawTraces['chunks'] as $chunk) {
                $spans = $chunk['spans'] ?? [];
                $chunks[] = isset($spans[0]['trace_id']) ? $this->fromV04Trace($spans) : $chunk;
            }
        }
        foreach ($chunks as &$chunk) {
            if (trim($chunk['trace_id'] ?? '', '0') === '') {
                TestCase::fail('Chunk has zero trace_id');
            }
            $chunk['spans'] = $this->parseRawDumpedSpans($chunk['spans'] ?? []);
        }
        unset($chunk);
        return $chunks;
    }

    /**
     * A v0.4 trace as a V1 chunk: the trace-level tags v0.4 keeps on spans become chunk fields, and
     * the payload-level ones go to $dumpedPayload.
     */
    private function fromV04Trace(array $spans)
    {
        $chunk = [];
        $traceIdHigh = 0;
        foreach ($spans as &$span) {
            $meta = $span['meta'] ?? [];
            $metrics = $span['metrics'] ?? [];
            foreach (self::$v04PayloadAttributes as $key) {
                if (isset($meta[$key])) {
                    $this->dumpedPayload['attributes'][$key] = $meta[$key];
                    unset($span['meta'][$key]);
                }
            }
            if (isset($meta['_dd.hostname'])) {
                $this->dumpedPayload['hostname'] = $meta['_dd.hostname'];
                unset($span['meta']['_dd.hostname']);
            }
            if (isset($meta['_dd.p.tid'])) {
                $traceIdHigh = $meta['_dd.p.tid'];
            }
            if (isset($metrics['_sampling_priority_v1'])) {
                $chunk['priority'] = (int)$metrics['_sampling_priority_v1'];
            }
            if (isset($meta['_dd.origin'])) {
                $chunk['origin'] = $meta['_dd.origin'];
            }
            if (isset($meta['_dd.p.dm'])) {
                // "-N" (legacy "<hash>-N"): the digits after the last '-'.
                $chunk['sampling_mechanism'] = (int)substr(strrchr('-' . $meta['_dd.p.dm'], '-'), 1);
            }
        }
        unset($span);
        $traceIdLow = isset($spans[0]['trace_id']) ? SpanChecker::decimalToHex($spans[0]['trace_id'], 16) : '';
        $chunk = ['trace_id' => str_pad($traceIdHigh, 16, '0', STR_PAD_LEFT) . $traceIdLow] + $chunk;
        $chunk['spans'] = array_map([$this, 'fromV04Span'], $spans);
        return $chunk;
    }

    /**
     * A v0.4 span in the V1 view shape. The fields v0.4 keeps in meta/metrics move back to the span,
     * links get hex ids. Attribute values stay in their (lossy) v0.4 form, so the span is marked for
     * SpanChecker to compare expectations in that form too.
     */
    private function fromV04Span(array $span)
    {
        $attributes = ($span['meta'] ?? []) + ($span['metrics'] ?? []);
        unset($span['meta'], $span['metrics'], $span['trace_id']);
        unset($attributes['_dd.origin'], $attributes['_dd.p.tid'], $attributes['_dd.p.dm'], $attributes['_sampling_priority_v1']);
        foreach (['env', 'version', 'component'] as $field) {
            if (isset($attributes[$field])) {
                $span[$field] = $attributes[$field];
                unset($attributes[$field]);
            }
        }
        $kind = array_search($attributes['span.kind'] ?? null, SpanKindNames::NAMES, true);
        if ($kind !== false) {
            $span['span_kind'] = $kind;
            unset($attributes['span.kind']);
        }
        if (!empty($span['error'])) {
            $span['error'] = true;
        } else {
            unset($span['error']);
        }
        if (isset($attributes['events']) && is_array($events = json_decode($attributes['events'], true))) {
            $span['span_events'] = $events;
            unset($attributes['events']);
        }
        $links = $span['span_links'] ?? [];
        unset($span['span_links']);
        foreach ($links as $link) {
            $span['span_links'][] = SpanChecker::hexSpanLink($link) + array_intersect_key($link, ['attributes' => 1]);
        }
        if ($attributes) {
            $span['attributes'] = $attributes;
        }
        $span['_wire'] = 'v0.4';
        return $span;
    }

    private function parseRawDumpedSpans($rawSpans)
    {
        $spans = [];
        foreach ($rawSpans as $rawSpan) {
            if (empty($rawSpan['resource'])) {
                TestCase::fail(sprintf("Span '%s' has empty resource name", $rawSpan['name']));
                return;
            }

            if (($rawSpan['parent_id'] ?? "") == "0") {
                unset($rawSpan['parent_id']);
            }

            $spans[] = $rawSpan;
        }
        return $spans;
    }

    /**
     * Parses the data dumped by the fake agent and returns the parsed traces.
     *
     * @return array
     * @throws \Exception
     */
    private function parseTracesFromDumpedData($until = null, $throw = false)
    {
        $loaded = $this->retrieveDumpedTraceData($until, $throw);
        if (!$loaded) {
            return [];
        }

        if (count($loaded) > 1) {
            // There are multiple bodies. Parse them all and return them.
            $dumps = [];
            foreach ($loaded as $dump) {
                if (!isset($dump['body'])) {
                    $dumps[] = [];
                } else {
                    $dumps = array_merge($dumps, $this->parseRawDumpedTraces(json_decode($dump['body'], true)));
                }
            }
        } else {
            $uniqueRequest = $loaded[0];

            if (!isset($uniqueRequest['body'])) {
                return [];
            }

            $rawTraces = json_decode($uniqueRequest['body'], true);
            $dumps = $this->parseRawDumpedTraces($rawTraces);
        }

        // Ensure stable sorting; sort order isn't guaranteed with sidecar trace sender
        // Sorting by end of root span
        usort($dumps, function ($a, $b) {
            return $a['spans'][0]["start"] + $a['spans'][0]["duration"] <=> $b['spans'][0]["start"] + $b['spans'][0]["duration"];
        });

        return $dumps;
    }

    public function parseMultipleRequestsFromDumpedData()
    {
        $manyRequests = $this->retrieveDumpedTraceData();
        if (!$manyRequests) {
            return [];
        }

        // For now we only support asserting traces against one dump at a time.
        $tracesAllRequests = [];

        // We receive back an array of traces
        foreach ($manyRequests as $uniqueRequest) {
            // error_log('Request: ' . print_r($uniqueRequest, 1));
            $rawTraces = json_decode($uniqueRequest['body'], true);
            $tracesAllRequests[] = $this->parseRawDumpedTraces($rawTraces);
        }

        return $tracesAllRequests;
    }

    /**
     * Returns the raw response body, if any, or null otherwise.
     * @param callable|null $until
     */
    public function retrieveDumpedData($until = null, $throw = false)
    {
        return $this->retrieveAnyDumpedData($until, $throw);
    }

    /**
     * Returns the raw response body, if any, or null otherwise.
     * @param callable|null $until
     */
    public function retrieveDumpedMetrics($until = null, $throw = false)
    {
        return $this->retrieveAnyDumpedData($until, $throw, true);
    }

    /** @param callable|null $until */
    public function retrieveAnyDumpedData($until, $throw, $metrics = false) {
        $until = $until ?? $this->untilFirstTraceRequest();

        $allResponses = [];

        // When tests run with the background sender enabled, there might be some delay between when a trace is flushed
        // and actually sent. While we should find a smart way to tackle this, for now we do it quick and dirty, in a
        // for loop.
        for ($attemptNumber = 1; $attemptNumber <= 50; $attemptNumber++) {
            if (\dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
                \dd_trace_synchronous_flush();
            }

            $curl = curl_init(self::$agentRequestDumperUrl . '/replay' . ($metrics ? '-metrics' : ''));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['x-datadog-test-session-token: ' . ini_get("datadog.trace.agent_test_session_token")]);
            // Retrieving data
            $response = self::curlWithoutSpan($curl);
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
                $loaded = json_decode($response, true);
                array_push($allResponses, ...$loaded);
                foreach ($loaded as $request) {
                    if ($until($request)) {
                        return $allResponses;
                    }
                }
                \usleep(10000);
            }
        }

        if ($throw) {
            throw new \LogicException('The expected request was not found');
        }

        return $allResponses;
    }

    /** @param callable|null $until */
    public function retrieveDumpedTraceData($until = null, $throw = false)
    {
        return array_values(array_filter($this->retrieveDumpedData($until, $throw), function ($request) {
            // Only return actual trace requests, filtering out telemetry, remote config, etc.
            return self::isTraceUri($request["uri"] ?? "");
        }));
    }

    private static function isTraceUri($uri)
    {
        return strpos($uri, "/v0.4/traces") === 0
            || strpos($uri, "/v0.7/traces") === 0
            || strpos($uri, "/v1.0/traces") === 0;
    }

    function untilNumberOfTraces($number) {
        $count = 0;
        return function ($request) use (&$count, $number) {
            $count += $request['headers']['X-Datadog-Trace-Count'] ?? $request["headers"]["x-datadog-trace-count"] ?? 0;
            return $count >= $number;
        };
    }

    function untilFirstTraceRequest() {
        return function ($request) {
            return self::isTraceUri($request["uri"] ?? "");
        };
    }

    function untilTelemetryRequest($metricName) {
        return function ($request) use ($metricName) {
            return (strpos($request["uri"] ?? "", "/telemetry/") === 0)
                && (strpos($request["body"] ?? "", $metricName) !== false)
            ;
        };
    }

    function untilSpan(SpanAssertion $assertion) {
        return function ($request) use ($assertion) {
            if (!self::isTraceUri($request["uri"] ?? "") || !isset($request['body'])) {
                return false;
            }
            $traces = $this->parseRawDumpedTraces(json_decode($request['body'], true));

            foreach ($traces as $trace) {
                try {
                    (new SpanChecker())->assertFlameGraph([$trace], [$assertion]);
                } catch (\Exception $e) {
                    continue;
                }

                return true;
            }

            return false;
        };
    }

    function until(...$expectations) {
        return function ($request) use (&$expectations) {
            foreach ($expectations as $key => $expect) {
                if ($expect($request)) {
                    unset($expectations[$key]);
                }
            }

            return !count($expectations);
        };
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

        $fn($tracer);

        // We have to close the active span for web frameworks, this is what is typically done in
        // `register_shutdown_function`.
        // We need yet to find a strategy, though, to make sure that the `register_shutdown_function` is actually there
        // and that do not magically disappear. Here we are faking things.
        $tracer->getActiveSpan()->finish();

        return $this->flushAndGetTraces();
    }

    /**
     * @return array[]
     */
    protected function flushAndGetTraces()
    {
        return \dd_trace_serialize_closed_spans()['chunks'];
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


function getHeaderWithEnvironment()
{
    try {
        $env = getenv();
    } catch (Exception $e) {
        $env = $_ENV;
    }
    $ddEnvVars = array_filter($env, function ($key) {
        return strpos($key, 'DD_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    if (count($ddEnvVars) > 0) {
        $ddEnvVarsString = implode(',', array_map(function ($key, $value) {
            return "$key=$value";
        }, array_keys($ddEnvVars), $ddEnvVars));
    }
    $peer_service_enabled = isset($env['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED'])
        ? $env['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED'] : 'false';
    if ($peer_service_enabled === 'true') {
        if (!isset($env['DD_TRACE_SPAN_ATTRIBUTE_SCHEMA'])) {
            $ddEnvVarsString .= ',DD_TRACE_SPAN_ATTRIBUTE_SCHEMA=v0.5';
        }
    }
    return $ddEnvVarsString;
}
