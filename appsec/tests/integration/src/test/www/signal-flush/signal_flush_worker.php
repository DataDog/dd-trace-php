<?php

/*
 * Model the workload from the crash report: a long-running CLI consumer uses
 * curl_multi_exec() while the tracer and AppSec exchange data with the
 * sidecar. The integration test sends SIGTERM from outside this process,
 * matching a container or service-manager shutdown.
 */

$readyFile = $argv[1];
$target = 'http://127.0.0.1/hello.php';
$iteration = 0;

while (true) {
    // Keep the normal sidecar connection active while curl_multi_exec()
    // supplies the application workload between sidecar calls. The SIGTERM
    // worker uses its separate, prebuilt connection concurrently.
    dd_trace_internal_fn('synchronous_flush', 100);

    // Do not allow the test to signal us until normal request initialization
    // and a successful synchronous flush have completed.
    if ($iteration === 0 && !file_exists($readyFile)) {
        file_put_contents($readyFile, (string) getmypid());
    }

    $multi = curl_multi_init();
    $handles = [];

    for ($request = 0; $request < 8; ++$request) {
        $handle = curl_init($target . '?worker=' . getmypid()
            . '&iteration=' . $iteration . '&request=' . $request);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, 2000);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $active);
    } while ($active && $status === CURLM_OK);

    foreach ($handles as $handle) {
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    ++$iteration;
}
