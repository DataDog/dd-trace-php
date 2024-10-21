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
        if (!\dd_trace_env_config("DD_APPSEC_RASP_ENABLED")) {
            return Integration::LOADED;
        }

        if (!function_exists('datadog\appsec\push_address')) {
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

        return Integration::LOADED;
    }

    private static function preHook($variant)
    {
        return static function (HookData $hook) use ($variant) {
            if (count($hook->args) == 0 || !is_string($hook->args[0])) {
                return;
            }

            $protocol = [];
            if (
                preg_match('/^[a-z]+\:\/\//', $hook->args[0], $protocol) &&
                isset($protocol[0]) &&
                $protocol[0] !== 'file')
            {
                return;
            }

            \datadog\appsec\push_address("server.io.fs.file", $hook->args[0], true);
        };
    }

}
