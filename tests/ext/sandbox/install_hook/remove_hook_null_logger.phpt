--TEST--
Remove hook
--ENV--
DD_TRACE_LOGS_ENABLED=false
--FILE--
<?php

namespace Psr\Log {
    interface LoggerInterface {
        public function log($level, $message, array $context = array());
    }

    abstract class AbstractLogger implements LoggerInterface {
        public function log($level, $message, array $context = array()) {
            echo static::class . "\n";
        }
    }

    class NullLogger extends AbstractLogger {
        public function log($level, $message, array $context = array()) {
            echo static::class . "\n";
        }
    }
}


namespace {
    $hook = \DDTrace\install_hook("Psr\Log\LoggerInterface::log", function () {
        echo "HOOKED: " . static::class . "\n";
    });
    \DDTrace\remove_hook($hook, \Psr\Log\NullLogger::class);

    $logger = new \Psr\Log\NullLogger();
    for ($i = 0; $i < 3; $i++) {
        $logger->log("info", "Hello World!");
    }
}

?>
--EXPECTF--
Psr\Log\NullLogger
Psr\Log\NullLogger
Psr\Log\NullLogger
