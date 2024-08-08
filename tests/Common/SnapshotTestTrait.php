<?php

namespace DDTrace\Tests\Common;

use DDTrace\GlobalTracer;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use PHPUnit\Framework\TestCase;

trait SnapshotTestTrait
{
    protected static $testAgentUrl = 'http://test-agent:9126';
    protected static $dogstatsdAddr = '127.0.0.1';
    protected static $dogstatsdPort = 9876;
    /** @var UDPServer */
    protected $server;
    protected $logFileSize = 0;

    private function decamelize($string): string
    {
        return ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', $string)), '_');
    }

    private function resetTracerState($tracer = null, $config = [])
    {
        // Reset the current C-level array of generated spans
        dd_trace_serialize_closed_spans();
        $transport = new DebugTransport();
        $tracer = $tracer ?: new Tracer($transport, null, $config);
        GlobalTracer::set($tracer);
    }

    /**
     * Generate a token based on the current test method and class to be used for the snapshotting session.
     *
     * Example: If a function DDTrace\Tests\Integrations\Framework\VX\TestClass::testFunction() calls
     * tracesFromWebRequest defined in this trait, which then calls generateToken, the token would be:
     * tests.integrations.framework.vx.test_class.test_function
     *
     * @return string The generated token
     */
    private function generateToken(): string
    {
        $class = get_class($this);
        $function = $this->getName();

        $class = explode('\\', $class);

        $class = array_map([$this, 'decamelize'], $class);
        $function = $this->decamelize($function);

        $class = implode('.', $class);
        $class = preg_replace('/^dd_trace\./', '', $class);

        return $class . '.' . $function;
    }

    private function startMetricsSnapshotSession()
    {
        $this->server = new UDPServer(self::$dogstatsdAddr, self::$dogstatsdPort);
    }

    /**
     * Start a snapshotting session associated with a given token.
     *
     * A GET request is made to the /test/session/start endpoint of the test agent.
     *
     * @param string $token The token to associate with the snapshotting session
     * @return void
     */
    private function startSnapshotSession(string $token, $snapshotMetrics = false, $logsFile = null)
    {

        $url = self::$testAgentUrl . '/test/session/start?test_session_token=' . $token;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            TestCase::fail('Error starting snapshot session: ' . $response);
        }

        if ($snapshotMetrics) {
            $this->startMetricsSnapshotSession();
        }

        if ($logsFile) {
            if (file_exists($logsFile)) {
                $this->logFileSize = (int)filesize($logsFile);
            } else {
                $this->logFileSize = 0;
            }
        }
    }

    /**
     * Wait for a given number of traces to be received by the test agent.
     *
     * @param string $token The token associated with the snapshotting session
     * @param int $numExpectedTraces The number of traces to wait for. Defaults to 0 (won't check for traces)
     * @return void
     */
    private function waitForTraces(string $token, int $numExpectedTraces = 0)
    {
        if ($numExpectedTraces === 0) {
            return;
        }

        $tracesUrl = self::$testAgentUrl . '/test/session/traces?test_session_token=' . $token;
        for ($i = 0; $i < 50; $i++) { // 50 is an arbitrary number
            try {
                $ch = curl_init($tracesUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $traces = json_decode($response, true);
                if ($traces && count($traces) === $numExpectedTraces) {
                    return;
                }
                usleep(100000); // 100ms
            } catch (\Exception $e) {
                // ignore
            }
        }

        TestCase::fail('Expected ' . $numExpectedTraces . ' traces, got ' . count($traces ?: []));
    }

    /**
     * Stop the snapshotting session associated with a given token and compare the received traces with the expected
     * ones.
     *
     * @param string $token The token associated with the snapshotting session
     * @param array $fieldsToIgnore An array of fields to ignore when comparing the received traces with the expected
     * @param int $numExpectedTraces The number of traces to wait for. Defaults to 1
     * @return void
     */
    private function stopAndCompareSnapshotSession(
        string $token,
        array $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'meta._dd.p.tid'],
        int $numExpectedTraces = 1,
        bool $snapshotMetrics = false,
        array $fieldsToIgnoreMetrics = ['openai.request.duration'],
        $logsFile = null,
        $fieldsToIgnoreLogs = ['timestamp', 'dd.trace_id', 'dd.span_id']
    ) {
        if ($snapshotMetrics) {
            $this->stopAndCompareMetricsSnapshotSession($token, $fieldsToIgnoreMetrics);
        }

        $this->waitForTraces($token, $numExpectedTraces);

        $url = self::$testAgentUrl . '/test/session/snapshot?ignores=' . implode(',', $fieldsToIgnore) .
            '&test_session_token=' . $token;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            TestCase::fail('Unexpected test failure during snapshot test: ' . $response);
        }

        TestCase::assertSame('200: OK', $response);

        if ($logsFile) {
            $this->compareLogsSnapshotSession($token, $logsFile, $fieldsToIgnoreLogs);
        }
    }

    private function compareLogsSnapshotSession(
        string $token,
        $logsFile,
        $fieldsToIgnore = ['timestamp', 'dd.trace_id', 'dd.span_id']
    ) {
        $logs = file_get_contents($logsFile, false, null, $this->logFileSize);
        $lines = explode("\n", $logs);
        // Relevant logs are assumed to be in JSON format. If they're not, shame on you.
        $lines = array_values(array_filter(array_map('json_decode', $lines)));

        $basePath = implode('/', array_slice(explode('/', getcwd()), 0, 4)); // /home/circleci/[app|datadog]
        $expectedLogsFile = $basePath . '/tests/snapshots/logs/' . $token . '.txt';
        if (file_exists($expectedLogsFile)) {
            $expectedLogs = file_get_contents($expectedLogsFile);
            $expectedLogs = explode("\n", $expectedLogs);
            $expectedLogs = array_values(array_filter(array_map('json_decode', $expectedLogs)));
            $this->compareLogs($expectedLogs, $lines, $fieldsToIgnore);
        } else {
            file_put_contents($expectedLogsFile, array_map('json_encode', $lines));
        }
    }

    private function compareLogs(array $expectedLogs, array $receivedLogs, array $fieldsToIgnore)
    {
        $expectedLogs = $this->filterLogs($expectedLogs, $fieldsToIgnore);
        $receivedLogs = $this->filterLogs($receivedLogs, $fieldsToIgnore);

        TestCase::assertEquals($expectedLogs, $receivedLogs, "Log's don't match");
    }

    private function filterLogs(array $logs, array $fieldsToIgnore)
    {
        return array_filter($logs, function ($log) use ($fieldsToIgnore) {
            foreach ($fieldsToIgnore as $fieldToIgnore) {
                if (isset($log->{$fieldToIgnore})) {
                    unset($log->{$fieldToIgnore});
                }
            }
            return true;
        });
    }

    private function stopAndCompareMetricsSnapshotSession(
        string $token,
        array $fieldsToIgnore = ['openai.request.duration']
    ) {
        $receivedMetrics = $this->server->dump();
        $this->server->close();

        $basePath = implode('/', array_slice(explode('/', getcwd()), 0, 4)); // /home/circleci/[app|datadog]
        $expectedMetricsFile = $basePath . '/tests/snapshots/metrics/' . $token . '.txt';
        if (file_exists($expectedMetricsFile)) {
            $expectedMetrics = file_get_contents($expectedMetricsFile);
            $this->compareMetrics($expectedMetrics, $receivedMetrics, $fieldsToIgnore);
        } else {
            file_put_contents($expectedMetricsFile, $receivedMetrics);
        }
    }

    private function compareMetrics($expectedMetrics, $receivedMetrics, $fieldsToIgnore)
    {
        $expectedMetrics = explode("\n", $expectedMetrics);
        $receivedMetrics = explode("\n", $receivedMetrics);

        $expectedMetrics = $this->decodeDogStatsDMetrics($expectedMetrics);
        $receivedMetrics = $this->decodeDogStatsDMetrics($receivedMetrics);

        $this->compareMetricsArrays($expectedMetrics, $receivedMetrics, $fieldsToIgnore);
    }

    /**
     * @param array $metrics
     * @return array{array{name: string, value: string, type: string, tags: array<string, string>}[]}
     */
    private function decodeDogStatsDMetrics($metrics)
    {
        $metrics = array_filter($metrics);

        // Format of DogStatsD metrics: metric_name:value|type|#tag1:value1,tag2:value2
        // Parts:                      |-> 0             |-> 1|-> 2
        $decodedMetrics = [];
        foreach ($metrics as $metric) {
            $parts = explode('|', $metric);

            $nameAndValue = explode(':', $parts[0]);
            $metricName = $nameAndValue[0];
            $value = $nameAndValue[1];

            $type = $parts[1];

            $tags = [];
            if (count($parts) > 2) {
                $parts[2] = substr($parts[2], 1); // Remove leading #
                $tags = explode(',', $parts[2]);
                $tags = array_map(function ($tag) {
                    return explode(':', $tag);
                }, $tags);
                $tags = array_combine(array_column($tags, 0), array_column($tags, 1));
            }
            $decodedMetrics[] = [
                'name' => $metricName,
                'value' => $value,
                'type' => $type,
                'tags' => $tags,
            ];
        }
        return $decodedMetrics;
    }

    private function compareMetricsArrays($expectedMetrics, $receivedMetrics, $fieldsToIgnore)
    {
        $expectedMetrics = $this->filterMetrics($expectedMetrics, $fieldsToIgnore);
        $receivedMetrics = $this->filterMetrics($receivedMetrics, $fieldsToIgnore);

        TestCase::assertEquals($expectedMetrics, $receivedMetrics, "Metrics don't match");
    }

    private function filterMetrics($metrics, $fieldsToIgnore)
    {
        return array_filter($metrics, function ($metric) use ($fieldsToIgnore) {
            foreach ($fieldsToIgnore as $fieldToIgnore) {
                if ($metric['name'] === $fieldToIgnore) {
                    return false;
                }
            }
            return true;
        });
    }

    public function tracesFromWebRequestSnapshot(
        $fn,
        $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'meta._dd.p.tid', 'start', 'duration'],
        $numExpectedTraces = 1,
        $tracer = null
    ) {
        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT=666666'); // Arbitrarily high value to avoid flakiness
        self::putEnv('DD_TRACE_AGENT_RETRIES=3');

        if ($tracer === null) {
            $this->resetTracerState();
        }

        $token = $this->generateToken();
        $this->startSnapshotSession($token);

        $fn($tracer);

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        $this->stopAndCompareSnapshotSession($token, $fieldsToIgnore, $numExpectedTraces);
    }

    public function isolateTracerSnapshot(
        $fn,
        $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'meta._dd.p.tid'],
        $numExpectedTraces = 1,
        $tracer = null,
        $config = [],
        $snapshotMetrics = false,
        $fieldsToIgnoreMetrics = ['openai.request.duration'],
        $logsFile = null, // If provided, logs snapshot will be compared
        $fieldsToIgnoreLogs = ['timestamp', 'dd.trace_id', 'dd.span_id']
    ) {
        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT=666666'); // Arbitrarily high value to avoid flakiness
        self::putEnv('DD_TRACE_AGENT_RETRIES=3');

        $token = $this->generateToken();
        $this->startSnapshotSession($token, $snapshotMetrics, $logsFile);

        $this->resetTracer($tracer, $config);

        $tracer = GlobalTracer::get();
        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            $tracer->startRootSpan("root span");
        }
        $fn($tracer);

        self::putEnv('DD_TRACE_SHUTDOWN_TIMEOUT');
        self::putEnv('DD_TRACE_AGENT_RETRIES');

        $traces = $this->flushAndGetTraces($tracer);
        if (!empty($traces)) {
            $this->sendTracesToTestAgent($traces);
        }

        $this->stopAndCompareSnapshotSession(
            $token,
            $fieldsToIgnore,
            $numExpectedTraces,
            $snapshotMetrics,
            $fieldsToIgnoreMetrics,
            $logsFile,
            $fieldsToIgnoreLogs
        );
    }

    public function snapshotFromTraces(
        $traces,
        $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack', 'meta._dd.p.tid'],
        $tokenSubstitute = null,
        $ignoreSampledAway = false
    ) {
        $token = $tokenSubstitute ?: $this->generateToken();
        $this->startSnapshotSession($token);

        if ($ignoreSampledAway) {
            $traces = $this->ignoreSampledTraces($traces);
        }

        $this->sendTracesToTestAgent($traces);

        $this->stopAndCompareSnapshotSession($token, $fieldsToIgnore, \count($traces));
    }

    protected function ignoreSampledTraces($traces) {
        $filteredSpans = [];
        $sampledTraceIDs = [];
        foreach ($traces as $trace) {
            foreach ($trace as $span) {
                if (isset($span['metrics']['_sampling_priority_v1']) && $span['metrics']['_sampling_priority_v1'] === 0) {
                    $sampledTraceIDs[$span['trace_id']] = true;
                }
            }
        }

        foreach ($traces as $trace) {
            foreach ($trace as $span) {
                if (!isset($sampledTraceIDs[$span['trace_id']])) {
                    $filteredSpans[] = $span;
                }
            }
        }

        return [$filteredSpans];
    }
}
