<?php

// Single-instance guard: held for this process's whole lifetime and released by
// the kernel when it dies, so index.php can tell whether a server is alive and
// two can never bind udp/80 (SO_REUSEADDR lets a second bind succeed and
// silently steal datagrams, so the bind itself is not a usable guard).
$lock = fopen(sys_get_temp_dir() . '/metrics-server.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$server = stream_socket_server("udp://0.0.0.0:80", $err, $errstr, STREAM_SERVER_BIND);
if ($server === false) {
    fwrite(STDERR, "metricsserver: cannot bind udp/80: $errstr ($err)\n");
    exit(1);
}

while (true) {
    $buf = stream_socket_recvfrom($server, 2048);
    file_get_contents("http://localhost/metrics?metrics=" . urlencode($buf));
}
