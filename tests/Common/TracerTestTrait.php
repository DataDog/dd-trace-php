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
use Exception;
use PHPUnit\Framework\TestCase;

trait TracerTestTrait
{
    protected static $agentRequestDumperUrl = 'http://request-replayer';
    protected static $testAgentUrl = 'http://test-agent:9126';

    protected static $webserverPort = 6666 + GLOBAL_PORT_OFFSET;

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

        $traces = $this->flushAndGetTraces();
        if (!empty($traces)) {
            $this->sendTracesToTestAgent($traces);
        }

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        return $traces;
    }

    public function sendTracesToTestAgent($traces)
    {
        // The data to be sent in the POST request
        $data_json = json_encode($traces);

        // The headers to be included in the request
        $headers = array(
            'Content-Type: application/json',
            'Datadog-Meta-Lang: php',
            'X-Datadog-Agent-Proxy-Disabled: true',
            'X-Datadog-Trace-Count: ' . count($traces),
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
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_json); // Set the POST data
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the response instead of outputting it
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // Set the headers

        // Execute the cURL session
        $response = self::curlWithoutSpan($curl);

        // Close the cURL session
        curl_close($curl);

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
    public function inWebServer($fn, $rootPath, $envs = [], $inis = [], &$curlInfo = null)
    {
        $retries = 1;
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
                    \curl_close($curl);
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

        // The we server has to be configured to send traces to the provided requests dumper.
        $fn($tracer);

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        return $this->parseTracesFromDumpedData($until);
    }

    private function parseRawDumpedTraces($rawTraces)
    {
        // error_log('RawTraces: ' . print_r($rawTraces, 1));

        if (empty($rawTraces['chunks'])) {
            return $this->parseRawDumpedTraces04($rawTraces);
        } else {
            return $this->parseRawDumpedTraces07($rawTraces);
        }
    }

    private function parseRawDumpedTraces04($rawTraces)
    {
        $traces = [];

        foreach ($rawTraces as $spansInTrace) {
            $traces[] = $this->parseRawDumpedSpans($spansInTrace);
        }

        return $traces;
    }

    private function parseRawDumpedTraces07($rawTraces)
    {
        $traces = [];

        foreach ($rawTraces['chunks'] as $chunk) {
            $traces[] = $this->parseRawDumpedSpans($chunk['spans']);
        }
        return $traces;
    }

    private function parseRawDumpedSpans($rawSpans)
    {
        $spans = [];
        foreach ($rawSpans as $rawSpan) {
            if (empty($rawSpan['resource'])) {
                TestCase::fail(sprintf("Span '%s' has empty resource name", $rawSpan['name']));
                return;
            }

            if ($rawSpan['trace_id'] == "0") {
                TestCase::fail(sprintf("Span '%s' has zero trace_id", $rawSpan['name']));
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
            return $a[0]["start"] + $a[0]["duration"] <=> $b[0]["start"] + $b[0]["duration"];
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
            // Filter telemetry requests
            return strpos($request["uri"] ?? "", "/telemetry/") !== 0;
        }));
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
            return (strpos($request["uri"] ?? "", "/v0.4/traces") === 0)
                || (strpos($request["uri"] ?? "", "/v0.7/traces") === 0)
            ;
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
            if (strpos($request["uri"] ?? "", "/telemetry/") === 0 || !isset($request['body'])) {
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
