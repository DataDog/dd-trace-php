--TEST--
The loader is able to load ddtrace
--ENV--
FAKE_FORWARDER_LOG_PATH=/tmp/test_loader_telemetry.log
DD_TELEMETRY_FORWARDER_PATH={PWD}/../bin/fake_forwarder.sh
--FILE--
<?php

$payload = json_decode(file_get_contents($_SERVER['FAKE_FORWARDER_LOG_PATH']), true);
echo json_encode($payload, JSON_PRETTY_PRINT);

?>
--EXPECTF--
{
    "metadata": {
        "runtime_name": "php",
        "runtime_version": "%s",
        "language_name": "php",
        "language_version": "%s",
        "tracer_version": "%s",
        "pid": %d
    },
    "points": [
        {
            "name": "library_entrypoint.complete",
            "tags": [
                "injection_forced:false"
            ]
        }
    ]
}
--CLEAN--
<?php
@unlink($_SERVER['FAKE_FORWARDER_LOG_PATH']);
?>
