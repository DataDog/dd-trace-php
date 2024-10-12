<?php

namespace Psr\Log;

interface LoggerInterface {
    function log();
}

class Logger implements LoggerInterface {
    function log() {
        echo "hey\n";
    }
}

echo "pre-hook\n";
(new Logger)->log();
echo "post-hook\n";
