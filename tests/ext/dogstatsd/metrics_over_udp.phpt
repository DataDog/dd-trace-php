--TEST--
Send DogStatsD metrics over a UDP socket
--ENV--
DD_DOGSTATSD_URL=http://127.0.0.1:9876
--FILE--
<?php

class UDPServer {
    private $socket;

    public function __construct($addr, $port) {
        if (!($this->socket = socket_create(AF_INET, SOCK_DGRAM, 0))) {
            $errorcode = socket_last_error();
            $errormsg = socket_strerror($errorcode);
            die("Couldn't create socket: [$errorcode] $errormsg\n");
        }

        if (!socket_bind($this->socket, $addr, $port)) {
            $errorcode = socket_last_error();
            $errormsg = socket_strerror($errorcode);
            die("Could not bind socket : [$errorcode] $errormsg\n");
        }
    }

    public function dump($iter = 100, $usleep = 100) {
        $buf = '';
        for ($i = 0; $i < $iter; ++$i) {
            usleep($usleep);
            $r = socket_recvfrom($this->socket, $buf, 2048, MSG_DONTWAIT, $remote_ip, $remote_port);
            if ($buf) {
                echo $buf."\n";
                $buf = '';
            }
        }
    }

    public function close() {
        socket_close($this->socket);
    }
}

$server = new UDPServer('127.0.0.1', 9876);

\DDTrace\dogstatsd_count("simple-counter", 42, ['foo' => 'bar', 'bar' => true]);
\DDTrace\dogstatsd_gauge("gogogadget", 21.4);

$server->dump();
$server->close();

?>
--EXPECT--
simple-counter:42|c|#foo=bar,bar=true
gogogadget:21.4|g
