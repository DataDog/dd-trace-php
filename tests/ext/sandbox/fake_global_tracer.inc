<?php

namespace DDTrace;

class GlobalTracer
{
    private static $instance;

    public static function set(Tracer $tracer)
    {
        self::$instance = $tracer;
    }

    public static function get()
    {
        if (null !== self::$instance) {
            return self::$instance;
        }
        return self::$instance = new Tracer();
    }
}
