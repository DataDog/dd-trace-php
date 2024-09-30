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
        if (!function_exists('\datadog\appsec\push_address')) {
            //Dont load Appsec wrappers is not available
            return Integration::LOADED;
        }

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
            if (count($hook->args) == 0 || !is_string($hook->args[0])) {
                return;
            }

            \datadog\appsec\push_address("server.io.fs.file", $hook->args[0], true);
        };
    }

}
