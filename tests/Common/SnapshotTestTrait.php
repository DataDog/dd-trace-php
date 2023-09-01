<?php

namespace DDTrace\Tests\Common;

use DDTrace\GlobalTracer;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use PHPUnit\Framework\TestCase;

trait SnapshotTestTrait
{
    protected static $testAgentUrl = 'http://test-agent:9126';

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

    /**
     * Start a snapshotting session associated with a given token.
     *
     * A GET request is made to the /test/session/start endpoint of the test agent.
     *
     * @param string $token The token to associate with the snapshotting session
     * @return void
     */
    private function startSnapshotSession(string $token)
    {

        $url = self::$testAgentUrl . '/test/session/start?test_session_token=' . $token;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            TestCase::fail('Error starting snapshot session: ' . $response);
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
        array $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack'],
        int $numExpectedTraces = 1
    ) {
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
    }

    public function tracesFromWebRequestSnapshot(
        $fn,
        $fieldsToIgnore = ['metrics.php.compilation.total_time_ms', 'meta.error.stack'],
        $numExpectedTraces = 1,
        $tracer = null
    ) {
        if ($tracer === null) {
            $this->resetTracerState();
        }

        $token = $this->generateToken();
        $this->startSnapshotSession($token);

        $fn($tracer);

        $this->stopAndCompareSnapshotSession($token, $fieldsToIgnore, $numExpectedTraces);
    }
}
