<?php

namespace {
    function show_curl_error_on_fail($ch)
    {
        $error = curl_error($ch);
        if ($error) {
            echo 'curl error (' . curl_errno($ch) . '): ' . $error . PHP_EOL;
            var_dump(curl_getinfo($ch));
        }
    }

    function show_curl_multi_error_on_fail($status)
    {
        if ($status != CURLM_OK) {
            echo 'curl multi error (' . $status . '): ' . curl_multi_strerror($status) . PHP_EOL;
        }
    }

    function curl_init_no_dns_cache()
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        // Disable DNS caching
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 0);
        return $ch;
    }
}

namespace DDTrace\Integrations\Curl {
    class CurlIntegration implements \DDTrace\Integration
    {
        function init(): int
        {
            return self::LOADED;
        }
    }
}
