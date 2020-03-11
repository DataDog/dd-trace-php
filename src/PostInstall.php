<?php

/* This file has peculiar requirements:
 * https://pear.php.net/manual/en/guide.migrating.postinstall.naming.php
 * https://pear.php.net/manual/en/guide.migrating.postinstall.structure.php
 */

class src_PostInstall_postinstall
{

    function init(PEAR_Config $config, PEAR_PackageFile_v2 $self, $lastInstalledVersion)
    {
        // todo: implement postinstall init
        // https://pear.php.net/manual/en/guide.users.commandline.config.php#guide.users.commandline.config.options
        var_export($config->get('php_dir'));
        echo "\n";
        return true;
    }

    function run(array $infoArray, $paramGroupId)
    {
        // todo: implement postinstall run
    }
}
