<?php

namespace DDTrace\Transport;

use DDTrace\Configuration;
use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\GlobalTracer;
use DDTrace\Log\LoggingTrait;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Transport;
use DDTrace\Util\ContainerInfo;

final class Http implements Transport
{
    use LoggingTrait;

    // Env variables to configure trace agent. They will be moved to a configuration class once we implement it.
    const AGENT_HOST_ENV = 'DD_AGENT_HOST';
    // The Agent has a payload cap of 10MB
    // https://github.com/DataDog/datadog-agent/blob/355a34d610bd1554572d7733454ac4af3acd89cd/pkg/trace/api/api.go#L31
    const AGENT_REQUEST_BODY_LIMIT = 10485760; // 10 * 1024 * 1024 => 10MB
    const TRACE_AGENT_PORT_ENV = 'DD_TRACE_AGENT_PORT';
    const AGENT_TIMEOUT_ENV = 'DD_TRACE_AGENT_TIMEOUT';
    const AGENT_CONNECT_TIMEOUT_ENV = 'DD_TRACE_AGENT_CONNECT_TIMEOUT';

    // Default values for trace agent configuration
    const DEFAULT_AGENT_HOST = 'localhost';
    const DEFAULT_TRACE_AGENT_PORT = '8126';
    const DEFAULT_TRACE_AGENT_PATH = '/v0.3/traces';
    const PRIORITY_SAMPLING_TRACE_AGENT_PATH = '/v0.4/traces';
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

        $containerInfo = new ContainerInfo();
        if ($containerId = $containerInfo->getContainerId()) {
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
        $host = getenv(self::AGENT_HOST_ENV) ?: self::DEFAULT_AGENT_HOST;
        $port = getenv(self::TRACE_AGENT_PORT_ENV) ?: self::DEFAULT_TRACE_AGENT_PORT;
        $path = self::DEFAULT_TRACE_AGENT_PATH;
        $endpoint = "http://${host}:${port}${path}";

        $this->config = array_merge([
            'endpoint' => $endpoint,
            'connect_timeout' => getenv(self::AGENT_CONNECT_TIMEOUT_ENV) ?: self::DEFAULT_AGENT_CONNECT_TIMEOUT,
            'timeout' => getenv(self::AGENT_TIMEOUT_ENV) ?: self::DEFAULT_AGENT_TIMEOUT,
        ], $config);
    }

    /**
     * {@inheritdoc}
     */
    public function send(Tracer $tracer)
    {
        if ($this->isLogDebugActive() && function_exists('dd_tracer_circuit_breaker_info')) {
            self::logDebug('circuit breaker status: closed => {closed}, total_failures => {total_failures},'
            . 'consecutive_failures => {consecutive_failures}, opened_timestamp => {opened_timestamp}, '
            . 'last_failure_timestamp=> {last_failure_timestamp}', dd_tracer_circuit_breaker_info());
        }

        if (function_exists('dd_tracer_circuit_breaker_can_try') && !dd_tracer_circuit_breaker_can_try()) {
            self::logError('Reporting of spans skipped due to open circuit breaker');
            return;
        }
        $tracesCount = $tracer->getTracesCount();
        $tracesPayload = $this->encoder->encodeTraces($tracer);
        self::logDebug('About to send trace(s) to the agent');

        // We keep the endpoint configuration option for backward compatibility instead of moving to an 'agent base url'
        // concept, but this should be probably revisited in the future.
        $endpoint = $this->isPrioritySamplingUsed() ? str_replace(
            self::DEFAULT_TRACE_AGENT_PATH,
            self::PRIORITY_SAMPLING_TRACE_AGENT_PATH,
            $this->config['endpoint']
        ) : $this->config['endpoint'];

        $this->sendRequest($endpoint, $this->headers, $tracesPayload, $tracesCount);
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
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, $this->config['timeout']);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, $this->config['connect_timeout']);

        $curlHeaders = [
            'Content-Type: ' . $this->encoder->getContentType(),
            'Content-Length: ' . $bodySize,
            'X-Datadog-Trace-Count: ' . $tracesCount,
        ];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $curlHeaders);

        if (($response = curl_exec($handle)) === false) {
            self::logError('Reporting of spans failed: {num} / {error}', [
                'error' => curl_error($handle),
                'num' => curl_errno($handle),
            ]);
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
        return Configuration::get()->isPrioritySamplingEnabled()
            && $tracer->getPrioritySampling() !== PrioritySampling::UNKNOWN;
    }
}
