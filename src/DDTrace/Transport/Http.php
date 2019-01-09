<?php

namespace DDTrace\Transport;

use DDTrace\Configuration;
use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\Log\Logger;
use DDTrace\Log\LoggerInterface;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Transport;
use DDTrace\GlobalTracer;

final class Http implements Transport
{
    // Env variables to configure trace agent. They will be moved to a configuration class once we implement it.
    const AGENT_HOST_ENV = 'DD_AGENT_HOST';
    const TRACE_AGENT_PORT_ENV = 'DD_TRACE_AGENT_PORT';

    // Default values for trace agent configuration
    const DEFAULT_AGENT_HOST = 'localhost';
    const DEFAULT_TRACE_AGENT_PORT = '8126';
    const DEFAULT_TRACE_AGENT_PATH = '/v0.3/traces';
    const PRIORITY_SAMPLING_TRACE_AGENT_PATH = '/v0.4/traces';

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

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Encoder $encoder, LoggerInterface $logger = null, array $config = [])
    {
        $this->configure($config);

        $this->encoder = $encoder;
        $this->logger = $logger ?: Logger::get();

        $this->setHeader('Datadog-Meta-Lang', 'php');
        $this->setHeader('Datadog-Meta-Lang-Version', \PHP_VERSION);
        $this->setHeader('Datadog-Meta-Lang-Interpreter', \PHP_SAPI);
        $this->setHeader('Datadog-Meta-Tracer-Version', \DDTrace\Tracer::VERSION);
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
        ], $config);
    }

    public function send(array $traces)
    {
        $tracesPayload = $this->encoder->encodeTraces($traces);

        // We keep the endpoint configuration option for backward compatibility instead of moving to an 'agent base url'
        // concept, but this should be probably revisited in the future.
        $endpoint = $this->isPrioritySamplingUsed() ? str_replace(
            self::DEFAULT_TRACE_AGENT_PATH,
            self::PRIORITY_SAMPLING_TRACE_AGENT_PATH,
            $this->config['endpoint']
        ) : $this->config['endpoint'];

        $this->sendRequest($endpoint, $this->headers, $tracesPayload);
    }

    public function setHeader($key, $value)
    {
        $this->headers[(string) $key] = (string) $value;
    }

    public function getConfig()
    {
        return $this->config;
    }

    private function sendRequest($url, array $headers, $body)
    {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        $curlHeaders = [
            'Content-Type: ' . $this->encoder->getContentType(),
            'Content-Length: ' . strlen($body),
        ];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $curlHeaders);

        if (curl_exec($handle) === false) {
            $this->logger->debug(sprintf(
                'Reporting of spans failed: %s, error code %s',
                curl_error($handle),
                curl_errno($handle)
            ));

            return;
        }

        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($statusCode === 415) {
            $this->logger->debug('Reporting of spans failed, upgrade your client library.');
            return;
        }

        if ($statusCode !== 200) {
            $this->logger->debug(
                sprintf('Reporting of spans failed, status code %d', $statusCode)
            );
            return;
        }
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
