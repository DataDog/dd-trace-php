<?php

class UDSServer {
    private $socket;

    public function __construct($path) {
        if (!($this->socket = socket_create(AF_UNIX, SOCK_DGRAM, 0))) {
            $errorcode = socket_last_error();
            $errormsg = socket_strerror($errorcode);
            die("Couldn't create socket: [$errorcode] $errormsg\n");
        }

        if (!socket_bind($this->socket, $path)) {
            $errorcode = socket_last_error();
            $errormsg = socket_strerror($errorcode);
            die("Could not bind socket : [$errorcode] $errormsg\n");
        }

        // On the CI, when this test is ran using "pecl run-tests" with sudo
        // the Unix socket is owned by root while the sidecar process is ran as another user
        chmod($path, 0777);
    }

    public function dump($expected, $iter = 5000) {
        $lines = [];
        for ($i = 0; $i < $iter; ++$i) {
            usleep(100);
            if (socket_recvfrom($this->socket, $buf, 2048, MSG_DONTWAIT, $remote_ip, $remote_port)) {
                $lines[] = "$buf\n";
                if (count($lines) == $expected) {
                    sort($lines, SORT_STRING);
                    echo implode($lines);
                    return;
                }
            }
        }
    }

    public function close() {
        socket_close($this->socket);
    }
}
