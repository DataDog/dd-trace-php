<?php

namespace DDTrace\Integrations\Logs;

class LogsIntegration implements \DDTrace\Integration {
    public function init(): int {
        \DDTrace\install_hook("Psr\Log\LoggerInterface::log", function () { echo "hooked LoggerInterface::log()\n"; });
        return self::LOADED;
    }
}
