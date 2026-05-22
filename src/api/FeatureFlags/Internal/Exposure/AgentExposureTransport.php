<?php

namespace DDTrace\FeatureFlags\Internal\Exposure;

final class AgentExposureTransport implements ExposureTransport
{
    const EXPOSURE_PATH = '/evp_proxy/v2/api/v2/exposures';
    const EVP_SUBDOMAIN = 'event-platform-intake';

    private $agentUrl;
    private $timeoutSeconds;

    public function __construct($agentUrl = null, $timeoutSeconds = 2.0)
    {
        $this->agentUrl = $agentUrl;
        $this->timeoutSeconds = (float) $timeoutSeconds;
    }

    public function send(array $payload)
    {
        $encoded = json_encode($payload);
        if (!is_string($encoded)) {
            return false;
        }

        $agentUrl = $this->agentUrl ?: self::resolveAgentUrl();
        if (strncmp($agentUrl, 'unix://', 7) === 0) {
            return $this->sendToUnixSocket(substr($agentUrl, 7), $encoded);
        }

        return $this->sendToHttpAgent($agentUrl, $encoded);
    }

    public static function resolveAgentUrl()
    {
        $agentUrl = self::envConfig('DD_TRACE_AGENT_URL');
        if (is_string($agentUrl) && $agentUrl !== '') {
            return $agentUrl;
        }

        $host = self::envConfig('DD_AGENT_HOST');
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }

        if (strncmp($host, 'unix://', 7) === 0) {
            return $host;
        }

        $port = self::envConfig('DD_TRACE_AGENT_PORT');
        $port = is_numeric($port) ? (int) $port : 8126;
        if ($port <= 0 || $port > 65535) {
            $port = 8126;
        }

        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }

        return 'http://' . $host . ':' . $port;
    }

    private function sendToHttpAgent($agentUrl, $encoded)
    {
        $parts = parse_url($agentUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'http') {
            return false;
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 8126;
        if ($port <= 0 || $port > 65535) {
            return false;
        }

        $connectHost = $host;
        if (strpos($connectHost, ':') !== false && $connectHost[0] !== '[') {
            $connectHost = '[' . $connectHost . ']';
        }

        $socket = @stream_socket_client(
            'tcp://' . $connectHost . ':' . $port,
            $errno,
            $errstr,
            $this->timeoutSeconds
        );
        if (!is_resource($socket)) {
            return false;
        }

        self::setSocketTimeout($socket, $this->timeoutSeconds);

        if (!$this->writeAll($socket, self::buildHttpRequest($connectHost . ':' . $port, $encoded))) {
            fclose($socket);
            return false;
        }

        $response = @stream_get_contents($socket);
        fclose($socket);

        return is_string($response) && preg_match('/^HTTP\/\S+\s+2\d\d\b/', $response) === 1;
    }

    private static function buildHttpRequest($hostHeader, $encoded)
    {
        return "POST " . self::EXPOSURE_PATH . " HTTP/1.1\r\n"
            . "Host: " . $hostHeader . "\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Datadog-EVP-Subdomain: " . self::EVP_SUBDOMAIN . "\r\n"
            . "Content-Length: " . strlen($encoded) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $encoded;
    }

    private function writeAll($socket, $request)
    {
        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private static function setSocketTimeout($socket, $timeoutSeconds)
    {
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1000000);
        if ($seconds < 0 || $microseconds < 0) {
            $seconds = 0;
            $microseconds = 0;
        }

        stream_set_timeout($socket, $seconds, $microseconds);
    }

    private function sendToUnixSocket($socketPath, $encoded)
    {
        $socket = @stream_socket_client(
            'unix://' . $socketPath,
            $errno,
            $errstr,
            $this->timeoutSeconds
        );
        if (!is_resource($socket)) {
            return false;
        }

        self::setSocketTimeout($socket, $this->timeoutSeconds);

        if (!$this->writeAll($socket, self::buildHttpRequest('localhost', $encoded))) {
            fclose($socket);
            return false;
        }

        $response = @stream_get_contents($socket);
        fclose($socket);

        return is_string($response) && preg_match('/^HTTP\/\S+\s+2\d\d\b/', $response) === 1;
    }

    private static function envConfig($name)
    {
        if (function_exists('dd_trace_env_config')) {
            return \dd_trace_env_config($name);
        }

        $value = getenv($name);

        return $value === false ? '' : $value;
    }
}
