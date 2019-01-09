<?php

namespace DDTrace;

use DDTrace\Encoders\Json;
use DDTrace\Transport\Http;

class Bootstrap
{
    private static $bootstrapped = false;

    public static function once()
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;
        self::resetTracer();

        register_shutdown_function(function() {
            $tracer = GlobalTracer::get();
            $scopeManager = $tracer->getScopeManager();
            $scopeManager->close();
            $tracer->flush();
        });
    }

    public static function resetTracer()
    {
        $tracer = new Tracer(new Http(new Json()));
        GlobalTracer::set($tracer);
    }
}
