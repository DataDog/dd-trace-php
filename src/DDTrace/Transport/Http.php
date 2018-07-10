<?php

namespace DDTrace\Transport;

use DDTrace\Encoder;
use DDTrace\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Http implements Transport
{
    const DEFAULT_ENDPOINT = 'http://localhost:8126/v0.3/traces';

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
        $this->encoder = $encoder;
        $this->logger = $logger ?: new NullLogger();
        $this->config = array_merge([
            'endpoint' => self::DEFAULT_ENDPOINT,
        ], $config);
    }

    public function send(array $traces)
    {
        $tracesPayload = $this->encoder->encodeTraces($traces);

        $this->sendRequest($this->config['endpoint'], $this->headers, $tracesPayload);
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
        curl_setopt($handle, CURLOPT_POST, 1);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array_merge($headers, [
            'Content-Type: ' . $this->encoder->getContentType(),
            'Content-Length: ' . strlen($body),
        ]));

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
}
