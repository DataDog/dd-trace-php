--TEST--
Test DogStatsD configuration with DD_DOGSTATSD_HOST and DD_DOGSTATSD_PORT
--SKIPIF--
<?php if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test'); ?>
--ENV--
DD_DOGSTATSD_HOST=192.168.1.1
DD_DOGSTATSD_PORT=9876
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

$server = new UDPServer('192.168.1.1', 9876);

\DDTrace\dogstatsd_count("test.host.port.config", 42, ['test' => 'host_port']);

$server->dump(1);
$server->close();

?>
--EXPECT--
test.host.port.config:42|c|#service:metrics_host_config.php,test:host_port 