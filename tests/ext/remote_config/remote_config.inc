<?php

function rc_base_url() {
    return sprintf(
        'http://%s:%d',
        getenv('DD_AGENT_HOST') ?: 'request-replayer',
        getenv('DD_TRACE_AGENT_PORT') ?: '80'
    );
}

function reset_request_replayer() {
    file_get_contents(rc_base_url() . "/clear-dumped-data");
}

function put_rc_file($path, $contents) {
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'header' => 'Content-type: application/json', 'content' => $contents]]);
    file_get_contents(rc_base_url() . "/add-rc-config-file?path=" . rawurlencode($path), false, $ctx);
}

function del_rc_file($path) {
    file_get_contents(rc_base_url() . "/del-rc-config-file?path=" . rawurlencode($path));
}

function put_dynamic_config_file($configs, $service = null, $env = null) {
    $json = [
        "action" => "enable",
        "service_target" => [
            "service" => $service ?? ((\DDTrace\root_span() ? \DDTrace\root_span()->service : ini_get("datadog.service")) ?: "unnamed-php-service"),
            "env" => $env ?? (ini_get("datadog.env") ?: "none"),
        ],
        "lib_config" => $configs,
    ];
    $data = json_encode($json);
    $path = "datadog/2/APM_TRACING/" . sha1($data) . "/config";
    put_rc_file($path, $data);
    return $path;
}
