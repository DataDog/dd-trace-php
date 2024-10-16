<?php

namespace DDTrace\Integrations\Filesystem {
    class FilesystemIntegration implements \DDTrace\Integration
    {
        function init(): int
        {
            return self::LOADED;
        }
    }
}
