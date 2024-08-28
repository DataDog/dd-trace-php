<?php

namespace DDTrace\Integrations\Filesystem;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;

class FilesystemIntegration extends Integration
{
    const NAME = "filesystem";

    public function init(): int
    {
        \DDTrace\install_hook(
            'file_get_contents',
            self::preHook('file_get_contents'),
            null
        );

        \DDTrace\install_hook(
            'file_put_contents',
            self::preHook('file_put_contents'),
            null
        );

        \DDTrace\install_hook(
            'fopen',
            self::preHook('fopen'),
            null
        );

        \DDTrace\install_hook(
            'readfile',
            self::preHook('readfile'),
            null
        );

        \DDTrace\install_hook(
            'stat',
            self::preHook('stat'),
            null
        );

        \DDTrace\install_hook(
            'lstat',
            self::preHook('lstat'),
            null
        );

        return Integration::LOADED;
    }

    private static function preHook($variant)
    {
        return static function (HookData $hook) use ($variant) {
            $time_start = microtime(true);
            if (count($hook->args) == 0 || !is_string($hook->args[0])) {
                return;
            }

            if (!$hook->span()->parent) {
                return;
            }
            $span = $hook->span()->parent;

            $filename = $hook->args[0];
            if (function_exists('\datadog\appsec\push_address')) {
                \datadog\appsec\push_address("server.io.fs.file", $filename, true);
            }
            $time_end = microtime(true);
            $span->metrics[Tag::APPSEC_RASP_DURATION_EXT] = $time_end - $time_start;
        };
    }

}
