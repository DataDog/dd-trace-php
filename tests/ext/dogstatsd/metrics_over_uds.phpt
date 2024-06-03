--TEST--
Send DogStatsD metrics over an Unix Domain Socket
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows');
if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test');
if (PHP_VERSION_ID < 70200) die('skip: this test triggers a bug in PHP < 7.2 (See https://github.com/php/php-src/pull/3408)');
?>
--ENV--
DD_DOGSTATSD_URL=unix:///tmp/ddtrace-test-metrics_over_uds.socket
DD_SERVICE=test-app
DD_ENV=test
DD_VERSION=1.12
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

$server = new UDSServer('/tmp/ddtrace-test-metrics_over_uds.socket');

\DDTrace\dogstatsd_count("counter-simple", 42, ['foo' => 'bar', 'bar' => true]);
\DDTrace\dogstatsd_gauge("gogogadget", 21.4);
\DDTrace\dogstatsd_distribution("my_disti", 22.22, ['distri' => 'bution']);
\DDTrace\dogstatsd_histogram("my_histo", 22.22, ['histo' => 'gram']);
\DDTrace\dogstatsd_set("set", 7, ['set' => '7']);

$server->dump(5);
$server->close();

?>
--EXPECT--
counter-simple:42|c|#env:test,service:test-app,version:1.12,foo:bar,bar:true
gogogadget:21.4|g|#env:test,service:test-app,version:1.12
my_disti:22.22|d|#env:test,service:test-app,version:1.12,distri:bution
my_histo:22.22|h|#env:test,service:test-app,version:1.12,histo:gram
set:7|s|#env:test,service:test-app,version:1.12,set:7
--CLEAN--
<?php
@unlink("/tmp/ddtrace-test-metrics_over_uds.socket");
?>
