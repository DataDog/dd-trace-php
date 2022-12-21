<?php

namespace DDTrace\Integrations\Pcntl;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;

class PcntlIntegration extends Integration
{
    const NAME = 'pcntl';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to forking
     */
    public function init()
    {
        if (!extension_loaded('pcntl')) {
            // pcntl is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        $trace_fork = function (SpanData $span, $args, $retval) {
            $span->name = $span->resource = 'pcntl_fork';
            if ($retval > 0) {
                $span->meta["fork.pid"] = $retval;
            } else {
                $span->meta["fork.errno"] = $errno = pcntl_get_last_error();
                $span->meta["fork.error"] = pcntl_strerror($errno);
            }
        };
        \DDTrace\trace_function('pcntl_fork', $trace_fork);
        \DDTrace\trace_function('pcntl_rfork', $trace_fork); // PHP 8.1+
        \DDTrace\trace_function('pcntl_forkx', $trace_fork); // PHP 8.2+

        return Integration::LOADED;
    }
}
