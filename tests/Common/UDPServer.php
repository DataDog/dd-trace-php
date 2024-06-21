<?php

namespace DDTrace\Tests\Common;

class UDPServer {
    private $socket;

    public function __construct($addr, $port) {
        if (!($this->socket = socket_create(AF_INET, SOCK_DGRAM, 0))) {
            $errorCode = socket_last_error();
            $errorMessage = socket_strerror($errorCode);
            die("Couldn't create socket: [$errorCode] $errorMessage\n");
        }

        if (!socket_bind($this->socket, $addr, $port)) {
            $errorCode = socket_last_error();
            $errorMessage = socket_strerror($errorCode);
            die("Couldn't bind socket: [$errorCode] $errorMessage\n");
        }


    }

    public function dump($iter = 100, $usleep = 100) {
        $ret = '';
        $buf = '';
        for ($i = 0; $i < $iter; $i++) {
            usleep($usleep);
            $r = socket_recvfrom($this->socket, $buf, 2048, MSG_DONTWAIT, $remote_ip, $remote_port);
            if ($buf) {
                $ret .= $buf . PHP_EOL;
                $buf = '';
            }
        }
        return $ret;
    }

    public function close() {
        socket_close($this->socket);
    }
}
