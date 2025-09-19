<?php

namespace DDTrace\Integrations\Filesystem {
    class FilesystemIntegration implements \DDTrace\Integration
    {
        static function init(): int
        {
            return self::LOADED;
        }
    }
}
