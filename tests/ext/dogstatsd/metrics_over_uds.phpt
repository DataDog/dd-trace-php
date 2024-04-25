--TEST--
Send DogStatsD metrics over an Unix Domain Socket
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows'); ?>
--ENV--
DD_DOGSTATSD_URL=unix:///tmp/ddtrace-test-metrics_over_uds.socket
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

        // On the CI, when this test is ran using "pecl run-tests" with sudo
        // the Unix socket is owned by root while the sidecar process is ran as another user
        chmod($path, 0777);
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

$server = new UDSServer('/tmp/ddtrace-test-metrics_over_uds.socket');

\DDTrace\dogstatsd_count("simple-counter", 42, ['foo' => 'bar', 'bar' => true]);
\DDTrace\dogstatsd_gauge("gogogadget", 21.4);
\DDTrace\dogstatsd_histogram("my_histo", 22.22, ['histo' => 'gram']);
\DDTrace\dogstatsd_distribution("my_disti", 22.22, ['distri' => 'bution']);
\DDTrace\dogstatsd_set("set", 7, ['set' => '7']);

$server->dump();
$server->close();

?>
--EXPECT--
simple-counter:42|c|#foo:bar,bar:true
gogogadget:21.4|g
my_histo:22.22|h|#histo:gram
my_disti:22.22|d|#distri:bution
set:7|s|#set:7
--CLEAN--
<?php
@unlink("/tmp/ddtrace-test-metrics_over_uds.socket");
?>
