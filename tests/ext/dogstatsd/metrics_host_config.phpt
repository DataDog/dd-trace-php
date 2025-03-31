--TEST--
Test DogStatsD configuration with DD_DOGSTATSD_HOST and DD_DOGSTATSD_PORT
--SKIPIF--
<?php if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test'); ?>
--ENV--
DD_DOGSTATSD_HOST=192.168.1.1
DD_DOGSTATSD_PORT=9876
--FILE--
<?php
class MockUDPSocket {
    public function stream_open($path, $mode, $options, &$opened_path) {
        $parts = parse_url($path);
        if (isset($parts['host']) && isset($parts['port'])) {
            echo "DogStatsD client attempted to connect to: " . $parts['host'] . ":" . $parts['port'] . "\n";
        }
        return true;
    }

    public function stream_write($data) {
        return strlen($data);
    }

    public function stream_close() {
        return true;
    }
}

// Register our mock socket wrapper for udp connections
stream_wrapper_register('udp', 'MockUDPSocket');

\DDTrace\dogstatsd_count("test.host.port.config", 42, ['test' => 'host_port']);
?>
--EXPECT--
DogStatsD client attempted to connect to: 192.168.1.1:9876