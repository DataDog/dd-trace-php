<?php

require_once __DIR__ . "/remote_config.inc";

function dsm_checkpoint_set() {
    return is_string(\DDTrace\Internal\dsm_set_checkpoint(['direction:out', 'topic:orders', 'type:kafka']));
}

// Applies the config with a sampling-rate marker, so that "no change" can be told from "not yet applied".
function dsm_put_config($lib_config, $marker) {
    $path = put_dynamic_config_file($lib_config + ["tracing_sampling_rate" => $marker]);
    await_remote_config(function () use ($marker) {
        return (float) ini_get("datadog.trace.sample_rate") === (float) $marker;
    });
    return $path;
}

function dsm_remove_config($path, $marker) {
    del_rc_file($path);
    await_remote_config(function () use ($marker) {
        return (float) ini_get("datadog.trace.sample_rate") !== (float) $marker;
    });
}

function dsm_state($label) {
    printf("%s: setting=%s checkpoint=%s\n", $label,
        dd_trace_env_config("DD_DATA_STREAMS_ENABLED") ? "on" : "off", dsm_checkpoint_set() ? "set" : "none");
}
