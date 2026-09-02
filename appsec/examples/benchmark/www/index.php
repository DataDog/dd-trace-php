<?php
$handler = static function () {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__appsec_ready') {
        header('X-Benchmark-Worker-Pid: ' . getmypid());
        $ready = \datadog\appsec\is_enabled()
            && function_exists('datadog\appsec\testing\request_exec')
            && \datadog\appsec\testing\request_exec([
                'server.request.path_params' => ['benchmark-readiness' => 'ok'],
            ]);
        if ($ready && isset($_GET['benchmark_worker_pid'])) {
            http_response_code(200);
            header('Content-Type: text/plain');
            echo getmypid();
        } else {
            http_response_code($ready ? 204 : 503);
        }
        return;
    }

    echo "ok";
};
if (function_exists('frankenphp_handle_request')) {
    while (frankenphp_handle_request($handler)) {}
} else {
    $handler();
}
