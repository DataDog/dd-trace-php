<?php

namespace App;

use ArrayObject;
use DDTrace\GlobalTracer;
use DDTrace\Contracts\Span;
use DDTrace\Contracts\Tracer;
use DDTrace\Format;

class Helper
{
    /**
     * @var array
     */
    private $collected;

    /**
     * @param string $key
     * @param mixed $value
     */
    public function collect($key, $value)
    {
        if (($span = $this->apm()->getActiveSpan()) instanceof Span) {
            $span->setTag($key, $value);
        }

        $this->collected[$key] = $value;
    }

    /**
     * Returns an ArrayObject with DD trace ID, span ID, etc.
     * Then the info can be passed in a message to another service in order to enable distributed tracing
     *
     * @return ArrayObject
     */
    public function getTraceInfo()
    {
        $tracer = $this->apm();
        $ddTrace = new ArrayObject();
        if (($span = $tracer->getActiveSpan()) instanceof Span) {
            $tracer->inject($span->getContext(), Format::TEXT_MAP, $ddTrace);
        }
        return $ddTrace;
    }

    /**
     * @return array
     */
    public function getCollectedProperties()
    {
        $collected = $this->collected;

        if (($span = $this->apm()->getActiveSpan()) instanceof Span) {
            $collected['trace_id'] = $span->getTraceId();
            $collected['span_id'] = $span->getSpanId();
        }

        return $collected;
    }

    /**
     * @return Tracer
     */
    private function apm()
    {
        return GlobalTracer::get();
    }
}
