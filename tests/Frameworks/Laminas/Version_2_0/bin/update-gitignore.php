<?php

declare(strict_types=1);

$gitIgnore = sprintf('%s/.gitignore', realpath(dirname(__DIR__)));
$rules     = file_get_contents($gitIgnore);
$rules     = preg_replace("#[\r\n]+composer.lock#s", '', $rules);
file_put_contents($gitIgnore, $rules);
unlink(__FILE__);
