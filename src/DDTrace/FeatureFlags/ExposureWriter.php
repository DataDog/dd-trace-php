<?php

namespace DDTrace\FeatureFlags;

/**
 * Batches and sends feature flag exposure events to the Datadog Agent's EVP proxy.
 */
class ExposureWriter
{
    const MAX_BUFFER_SIZE = 1000;

    /** @var array */
    private $buffer = [];

    /** @var int */
    private $droppedEvents = 0;

    /** @var string */
    private $agentUrl;

    public function __construct()
    {
        $this->agentUrl = $this->resolveAgentUrl();
    }

    /**
     * Add an exposure event to the buffer.
     *
     * @param array $event Exposure event data
     */
    public function enqueue(array $event)
    {
        if (count($this->buffer) >= self::MAX_BUFFER_SIZE) {
            $this->droppedEvents++;
            return;
        }
        $this->buffer[] = $event;
    }

    /**
     * Send all buffered exposure events as a batch to the EVP proxy.
     */
    public function flush()
    {
        if (empty($this->buffer)) {
            return;
        }

        $events = $this->buffer;
        $dropped = $this->droppedEvents;
        $this->buffer = [];
        $this->droppedEvents = 0;

        if ($dropped > 0 && function_exists('dd_trace_env_config') && \dd_trace_env_config('DD_TRACE_DEBUG')) {
            error_log("ddtrace/ffe: dropped $dropped exposure event(s) due to full buffer");
        }

        $payload = [
            'context' => [
                'service' => $this->getConfigValue('DD_SERVICE', ''),
                'env' => $this->getConfigValue('DD_ENV', ''),
                'version' => $this->getConfigValue('DD_VERSION', ''),
            ],
            'exposures' => $events,
        ];

        $url = rtrim($this->agentUrl, '/') . '/evp_proxy/v2/api/v2/exposures';
        $body = json_encode($payload);

        if (!function_exists('curl_init')) {
            return;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Datadog-EVP-Subdomain: event-platform-intake',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500,
            CURLOPT_CONNECTTIMEOUT_MS => 100,
        ]);

        $response = curl_exec($ch);
        if ($response === false && function_exists('dd_trace_env_config') && \dd_trace_env_config('DD_TRACE_DEBUG')) {
            error_log('ddtrace/ffe: failed to send exposures: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    /**
     * Return the number of events currently in the buffer.
     *
     * @return int
     */
    public function getBufferCount()
    {
        return count($this->buffer);
    }

    /**
     * Build a complete exposure event array.
     *
     * @param string $flagKey
     * @param string $variantKey
     * @param string $allocationKey
     * @param string|null $targetingKey
     * @param array $attributes
     * @return array
     */
    public static function buildEvent(
        $flagKey,
        $variantKey,
        $allocationKey,
        $targetingKey = null,
        array $attributes = []
    ) {
        return [
            'timestamp' => (int)(microtime(true) * 1000),
            'allocation' => ['key' => $allocationKey],
            'flag' => ['key' => $flagKey],
            'variant' => ['key' => $variantKey],
            'subject' => [
                'id' => $targetingKey ?? '',
                'attributes' => $attributes,
            ],
        ];
    }

    /**
     * Resolve the agent URL from environment configuration.
     *
     * Checks DD_TRACE_AGENT_URL first. If not set, constructs from
     * DD_AGENT_HOST (default: localhost) and DD_TRACE_AGENT_PORT (default: 8126).
     *
     * @return string
     */
    private function resolveAgentUrl()
    {
        $agentUrl = $this->getConfigValue('DD_TRACE_AGENT_URL', '');
        if ($agentUrl !== '') {
            return rtrim($agentUrl, '/');
        }

        $host = $this->getConfigValue('DD_AGENT_HOST', 'localhost');
        if ($host === '') {
            $host = 'localhost';
        }

        $port = $this->getConfigValue('DD_TRACE_AGENT_PORT', '8126');
        if ($port === '') {
            $port = '8126';
        }

        return 'http://' . $host . ':' . $port;
    }

    /**
     * Read a configuration value using dd_trace_env_config() if available,
     * otherwise fall back to getenv().
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    private function getConfigValue($name, $default = '')
    {
        if (function_exists('dd_trace_env_config')) {
            $value = \dd_trace_env_config($name);
            if ($value !== '' && $value !== false && $value !== null) {
                return (string)$value;
            }
            return $default;
        }

        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return $default;
    }
}
