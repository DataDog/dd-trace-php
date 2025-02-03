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
            return Integration::NOT_LOADED;
        }

        if (!function_exists('datadog\appsec\push_addresses')) {
            //Dont load Appsec wrappers is not available
            return Integration::NOT_LOADED;
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
           $uri_parsed = preg_match('/^([a-z]+)\:\/\//', $hook->args[0], $protocol);
           $protocol = isset($protocol[1]) ? $protocol[1]: "";

           $addresses = [];
           $rule = "";

           if (empty($protocol) || $protocol === 'file') {
                $addresses["server.io.fs.file"] = $hook->args[0];
                $rule = "lfi";
           }

           if (in_array($variant, ['file_get_contents', 'fopen']) &&
           in_array($protocol, ['http', 'https', 'ftp', 'ftps'])) {
                $addresses["server.io.net.url"] = $hook->args[0];
                $rule = "ssrf";
           }

            if (empty($addresses)) {
                return;
            }

            \datadog\appsec\push_addresses($addresses, $rule);
        };
    }

}
