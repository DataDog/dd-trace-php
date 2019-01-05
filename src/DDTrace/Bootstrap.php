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

        $tracer = new Tracer(new Http(new Json()));
        GlobalTracer::set($tracer);

        register_shutdown_function(function() use ($tracer) {
            $scopeManager = $tracer->getScopeManager();
            $scopeManager->close();
            $tracer->flush();
        });
    }
}
