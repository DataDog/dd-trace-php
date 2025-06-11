<?php
DDTrace\start_span();
$root = DDTrace\root_span();

$id = basename(__FILE__, ".php");

$root->service = $id.'-telemetry-app';
$root->meta['env'] = 'test-env';

DDTrace\close_span();

// At this stage, the service and env are stored to be used by telemetry
dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 300; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/'.$id.'-telemetry.out')) {
        $batches = [];
        foreach (file(__DIR__ . '/'.$id.'-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                if ($json) {
                    if ($json["application"]["service_name"] == "background_sender-php-service" || $json["application"]["service_name"] == "datadog-ipc-helper") {
                        continue;
                    }
                    array_push($batches, ...($json["request_type"] == "message-batch" ? $json["payload"] : [$json]));
                }
            }
        }
        $found = array_filter($batches, function ($json) {
            if ($json["request_type"] !== "app-started") {
                return false;
            }
            foreach($json["payload"]["configuration"] as $configuration) {
                if (strpos($configuration["name"], "sca_enabled") !== false) {
                    var_dump($configuration);
                    return true;
                }
            }
            return false;
        });
        if (count($found) == 1) {
            var_dump("Sent");
            break;
        }
    }
}
if ($i == 300) {
    var_dump(file(__DIR__ . '/'.$id.'-telemetry.out'));
}

?>
