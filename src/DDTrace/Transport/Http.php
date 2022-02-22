<?php

namespace DDTrace\Transport;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\GlobalTracer;
use DDTrace\Log\LoggingTrait;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Transport;

/** @deprecated Obsoleted by moving related code to internal. */
final class Http implements Transport
{
    use LoggingTrait;

    // Env variables to configure trace agent. They will be moved to a configuration class once we implement it.
    const AGENT_HOST_ENV = 'DD_AGENT_HOST';
    const TRACE_AGENT_URL_ENV = 'DD_TRACE_AGENT_URL';

    // The Agent has a payload cap of 10MB
    // https://github.com/DataDog/datadog-agent/blob/355a34d610bd1554572d7733454ac4af3acd89cd/pkg/trace/api/api.go#L31
    const AGENT_REQUEST_BODY_LIMIT = 10485760; // 10 * 1024 * 1024 => 10MB
    const TRACE_AGENT_PORT_ENV = 'DD_TRACE_AGENT_PORT';
    const AGENT_TIMEOUT_ENV = 'DD_TRACE_AGENT_TIMEOUT';
    const AGENT_CONNECT_TIMEOUT_ENV = 'DD_TRACE_AGENT_CONNECT_TIMEOUT';

    // Default values for trace agent configuration
    const DEFAULT_AGENT_HOST = 'localhost';
    const DEFAULT_TRACE_AGENT_PORT = '8126';
    const PRIORITY_SAMPLING_TRACE_AGENT_PATH = '/v0.4/traces';

    /* Keep these in sync with configuration.h's values */
    const DEFAULT_AGENT_CONNECT_TIMEOUT = 100;
    const DEFAULT_AGENT_TIMEOUT = 500;

    /**
     * @var Encoder
     */
    private $encoder;

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var array
     */
    private $config;

    public function __construct(Encoder $encoder, array $config = [])
    {
        $this->configure($config);

        $this->encoder = $encoder;

        $this->setHeader('Datadog-Meta-Lang', 'php');
        $this->setHeader('Datadog-Meta-Lang-Version', \PHP_VERSION);
        $this->setHeader('Datadog-Meta-Lang-Interpreter', \PHP_SAPI);
        $this->setHeader('Datadog-Meta-Tracer-Version', DD_TRACE_VERSION);

        $containerId = \DDTrace\System\container_id();
        if ($containerId) {
            $this->setHeader('Datadog-Container-Id', $containerId);
        }
    }

    /**
     * Configures this http transport.
     *
     * @param array $config
     */
    private function configure($config)
    {
        $host = ddtrace_config_read_env_or_ini(self::AGENT_HOST_ENV) ?: self::DEFAULT_AGENT_HOST;
        $port = ddtrace_config_read_env_or_ini(self::TRACE_AGENT_PORT_ENV) ?: self::DEFAULT_TRACE_AGENT_PORT;
        $traceAgentUrl = ddtrace_config_read_env_or_ini(self::TRACE_AGENT_URL_ENV) ?: "http://${host}:${port}";
        $path = self::PRIORITY_SAMPLING_TRACE_AGENT_PATH;
        $endpoint = "${traceAgentUrl}${path}";

        $this->config = array_merge([
            'endpoint' => $endpoint,
            'connect_timeout' => ddtrace_config_read_env_or_ini(self::AGENT_CONNECT_TIMEOUT_ENV)
                ?: self::DEFAULT_AGENT_CONNECT_TIMEOUT,
            'timeout' => ddtrace_config_read_env_or_ini(self::AGENT_TIMEOUT_ENV) ?: self::DEFAULT_AGENT_TIMEOUT,
        ], $config);
    }

    /**
     * {@inheritdoc}
     */
    public function send(Tracer $tracer)
    {
        $tracesCount = $tracer->getTracesCount();
        $tracesPayload = $this->encoder->encodeTraces($tracer);

        if ($tracesCount === 0) {
            self::logDebug('No finished traces to be sent to the agent');
            /* We should short-circuit here so the agent is never bothered, but
             * at time of writing tests were depending on existing behavior.
             * return;
             */
        }

        self::logDebug('About to send trace(s) to the agent');

        $this->sendRequest($this->config['endpoint'], $this->headers, $tracesPayload, $tracesCount);
    }

    public function setHeader($key, $value)
    {
        $this->headers[(string) $key] = (string) $value;
    }

    public function getConfig()
    {
        return $this->config;
    }

    private function sendRequest($url, array $headers, $body, $tracesCount)
    {
        $bodySize = strlen($body);
        // The 10MB payload cap is inclusive, thus we use >, not >=
        // https://github.com/DataDog/datadog-agent/blob/355a34d610bd1554572d7733454ac4af3acd89cd/pkg/trace/api/limited_reader.go#L37
        if ($bodySize > self::AGENT_REQUEST_BODY_LIMIT) {
            self::logError('Agent request payload of {bytes} bytes exceeds 10MB limit; dropping request', [
                'bytes' => $bodySize,
            ]);
            return;
        }

        $curlHeaders = [];

        /* Curl will add Expect: 100-continue if it is a POST over a certain size. The trouble is that CURL will
         * wait for *1 second* for 100 Continue response before sending the rest of the data. This wait is
         * configurable, but requires a newer curl than we have on CentOS 6. So instead we send an empty Expect.
         */
        if (!isset($headers['Expect'])) {
            $curlHeaders[] = "Expect:";
        }

        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }

        // Now that bgs is enabled by default, allow disabling it by disabling either option
        $bgsEnabled = \dd_trace_env_config('DD_TRACE_BGS_ENABLED')
            && \dd_trace_env_config('DD_TRACE_BETA_SEND_TRACES_VIA_THREAD');
        if (
            $bgsEnabled
            && $this->encoder->getContentType() === 'application/msgpack'
            && \dd_trace_send_traces_via_thread($tracesCount, $curlHeaders, $body)
        ) {
            return;
        }

        if ($this->isLogDebugActive() && function_exists('dd_tracer_circuit_breaker_info')) {
            self::logDebug('circuit breaker status: closed => {closed}, total_failures => {total_failures},'
                . 'consecutive_failures => {consecutive_failures}, opened_timestamp => {opened_timestamp}, '
                . 'last_failure_timestamp=> {last_failure_timestamp}', dd_tracer_circuit_breaker_info());
        }

        if (function_exists('dd_tracer_circuit_breaker_can_try') && !dd_tracer_circuit_breaker_can_try()) {
            self::logError('Reporting of spans skipped due to open circuit breaker');
            return;
        }

        $curlHeaders = \array_merge(
            $curlHeaders,
            [
                'Content-Type: ' . $this->encoder->getContentType(),
                'X-Datadog-Trace-Count: ' . $tracesCount,
            ]
        );

        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $this->config['timeout']);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $this->config['connect_timeout']);

        $isDebugEnabled = \ddtrace_config_debug_enabled();
        if ($isDebugEnabled) {
            $verbose = \fopen('php://temp', 'w+b');
            \curl_setopt($handle, \CURLOPT_VERBOSE, true);
            \curl_setopt($handle, \CURLOPT_STDERR, $verbose);
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $curlHeaders);

        if (($response = curl_exec($handle)) === false) {
            $curlTimedout =  \version_compare(\PHP_VERSION, '5.5', '<')
                ? \CURLE_OPERATION_TIMEOUTED
                : \CURLE_OPERATION_TIMEDOUT;
            $errno = \curl_errno($handle);
            $extra = '';
            if ($errno === $curlTimedout) {
                $timeout = $this->config['timeout'];
                $connectTimeout = $this->config['connect_timeout'];
                $extra = " (TIMEOUT_MS={$timeout}, CONNECTTIMEOUT_MS={$connectTimeout})";
            }
            self::logError('Reporting of spans failed: {num} / {error}{extra}', [
                'error' => curl_error($handle),
                'num' => $errno,
                'extra' => $extra,
            ]);

            if ($isDebugEnabled && $verbose !== false) {
                @\rewind($verbose);
                $verboseLog = @\stream_get_contents($verbose);
                if ($verboseLog !== false) {
                    self::logError($verboseLog);
                } else {
                    self::logError("Error while retrieving curl error log");
                }
            }

            function_exists('dd_tracer_circuit_breaker_register_error') && dd_tracer_circuit_breaker_register_error();

            return;
        }

        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($statusCode === 415) {
            self::logError('Reporting of spans failed, upgrade your client library');

            function_exists('dd_tracer_circuit_breaker_register_error') && dd_tracer_circuit_breaker_register_error();
            return;
        }

        if ($statusCode !== 200) {
            self::logError(
                'Reporting of spans failed, status code {code}: {response}',
                ['code' => $statusCode, 'response' => $response]
            );
            function_exists('dd_tracer_circuit_breaker_register_error') && dd_tracer_circuit_breaker_register_error();
            return;
        }

        function_exists('dd_tracer_circuit_breaker_register_success') && dd_tracer_circuit_breaker_register_success();
        self::logDebug('Traces successfully sent to the agent');
    }

    /**
     * Returns whether or not we should send these traces to the priority sampling aware trace agent endpoint.
     * This approach could be optimized in the future if we refactor how traces are organized in parent/child relations
     * but this would be out of scope at the moment.
     *
     * @return bool
     */
    private function isPrioritySamplingUsed()
    {
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        return \ddtrace_config_priority_sampling_enabled()
            && $tracer->getPrioritySampling() !== PrioritySampling::UNKNOWN;
    }
}
