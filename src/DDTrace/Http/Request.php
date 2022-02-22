<?php

namespace DDTrace\Http;

/** @deprecated Obsoleted by moving related code to internal. */
class Request
{
    /**
     * HTTP request headers as an associative array
     *
     * @param array $server
     * @return string[]
     */
    public static function getHeaders(array $server = [])
    {
        $headers = [];
        $server = $server ?: $_SERVER;
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $key = substr($key, 5);
            $key = str_replace(' ', '-', strtolower(str_replace('_', ' ', $key)));
            $headers[$key] = $value;
        }
        return $headers;
    }
}
