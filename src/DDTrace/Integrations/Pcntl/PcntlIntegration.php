<?php

namespace DDTrace\Integrations\Pcntl;

use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;

class PcntlIntegration extends Integration
{
    const NAME = 'pcntl';

    /**
     * Add instrumentation to forking
     */
    public static function init(): int
    {
        if (!extension_loaded('pcntl')) {
            // pcntl is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        $trace_fork = static function (SpanData $span, $args, $retval) {
            $span->name = $span->resource = 'pcntl_fork';
            $span->meta[Tag::COMPONENT] = self::NAME;
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
