<?php

function dd_trace_unserialize_trace_hex($message) {
    $hex = [];
    $length = strlen($message);
    for ($i = 0; $i < $length; $i++) {
        $hex[] = bin2hex($message[$i]);
    }
    return implode(' ', $hex);
}

function dd_trace_unserialize_trace_json(\DDTrace\Contracts\Tracer $tracer) {
    return json_encode($tracer->asArray());
}

final class FooTracer implements \DDTrace\Contracts\Tracer
{
    private $trace;
    /**
     * @param array $trace The data to return from asArray()
     */
    public function __construct(array $trace = [])
    {
        $this->trace = $trace;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveSpan()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $finishSpanOnClose = true, $options = [])
    {
    }

    /**
     * {@inheritdoc}
     */
    public function inject(\DDTrace\Contracts\SpanContext $spanContext, $format, &$carrier)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getRootScope()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSafeRootSpan()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function startIntegrationScopeAndSpan(\DDTrace\Integrations\Integration $integration, $operationName, $options = [])
    {
    }

    /**
     * {@inheritdoc}
     */
    public function asArray()
    {
        if (!empty($this->trace)) {
            return $this->trace;
        }
        // Default example from MessagePack website
        return [
            'compact' => true,
            'schema' => 0,
        ];
    }
}
