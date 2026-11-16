--TEST--
DogStatsD is disabled in agentlessmode
--SKIPIF--
<?php if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test'); ?>
--ENV--
DD_TRACE_AGENTLESS=true
DD_API_KEY=1234
DD_DOGSTATSD_URL=unix:///tmp/ddtrace-test-disabled_if_agentless.socket
--FILE--
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

$server = new UDSServer('/tmp/ddtrace-test-disabled_if_agentless.socket');

\DDTrace\dogstatsd_count("simple-counter", 42, ['foo' => 'bar', 'bar' => true]);
\DDTrace\dogstatsd_gauge("gogogadget", 21.4);
\DDTrace\dogstatsd_histogram("my_histo", 22.22, ['histo' => 'gram']);
\DDTrace\dogstatsd_distribution("my_disti", 22.22, ['distri' => 'bution']);
\DDTrace\dogstatsd_set("set", 7, ['set' => '7']);

$server->dump();
$server->close();

echo "end\n";

?>
--EXPECT--
end
--CLEAN--
<?php
@unlink("/tmp/ddtrace-test-disabled_if_agentless.socket");
?>
