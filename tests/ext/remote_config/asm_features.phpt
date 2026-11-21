--TEST--
ASM_FEATURES remote configs are parsed and merged by the tracer's remote config reader
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/asm_features
--FILE--
<?php

require __DIR__ . "/remote_config.inc";

reset_request_replayer();

function await_asm_features($activation, $auto_user_instrum) {
    $expected = ["activation" => $activation, "auto_user_instrum" => $auto_user_instrum];
    if (!await_remote_config(function () use ($expected) {
        return dd_trace_internal_fn("asm_features") == $expected;
    })) {
        echo "Timed out waiting for ", json_encode($expected), "\n";
    }
    echo json_encode(dd_trace_internal_fn("asm_features")), "\n";
}

// Registers the application, so that remote config targets this service
\DDTrace\start_span();

await_asm_features("unset", "undefined");

put_rc_file("datadog/2/ASM_FEATURES/activation_on/config", '{"asm":{"enabled":true}}');
await_asm_features("enabled", "undefined");

// Files only setting one of the features do not reset the other
put_rc_file("datadog/2/ASM_FEATURES/user_instrum/config", '{"auto_user_instrum":{"mode":"ANONYMIZATION"}}');
await_asm_features("enabled", "anonymization");

// The string form of the flag is accepted too
del_rc_file("datadog/2/ASM_FEATURES/activation_on/config");
put_rc_file("datadog/2/ASM_FEATURES/activation_off/config", '{"asm":{"enabled":"false"}}');
await_asm_features("disabled", "anonymization");

del_rc_file("datadog/2/ASM_FEATURES/activation_off/config");
await_asm_features("unset", "anonymization");

del_rc_file("datadog/2/ASM_FEATURES/user_instrum/config");
await_asm_features("unset", "undefined");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
{"activation":"unset","auto_user_instrum":"undefined"}
{"activation":"enabled","auto_user_instrum":"undefined"}
{"activation":"enabled","auto_user_instrum":"anonymization"}
{"activation":"disabled","auto_user_instrum":"anonymization"}
{"activation":"unset","auto_user_instrum":"anonymization"}
{"activation":"unset","auto_user_instrum":"undefined"}
