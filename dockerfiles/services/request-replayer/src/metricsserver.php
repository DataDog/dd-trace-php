<?php

$server = stream_socket_server("udp://0.0.0.0:80", $err, $errstr, STREAM_SERVER_BIND);

while (true) {
    $buf = stream_socket_recvfrom($server, 2048);
    file_get_contents("http://localhost/metrics?metrics=" . urlencode($buf));
}
