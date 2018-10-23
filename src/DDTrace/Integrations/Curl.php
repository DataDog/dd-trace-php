<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;

class Curl
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument curl', E_USER_WARNING);
            return;
        }
        if (!function_exists('curl_exec')) {
            trigger_error('curl is not loaded and connot be instrumented', E_USER_WARNING);
        }

        dd_trace('curl_exec', function ($ch) {
            $tracer = GlobalTracer::get();
            $scope = $tracer->startActiveSpan('PDO.__construct');
            $span = $scope->getSpan();

            $tracer->inject($span->getContext(), Formats\TEXT_MAP, $headers = []);
            var_dump($headers);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_walk($headers, function ($val, $key) {
                return "$key: $val";
            }));

            $result = curl_exec($ch);
            if ($result === false) {
                $span->setError(curl_error($ch));
            }

            $scope->close();

            return $result;
        });
    }
}
