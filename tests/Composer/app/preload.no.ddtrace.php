<?php

require __DIR__ . '/custom_autoloaders.php';
(new AutoloaderThatFails())->register();

file_put_contents(__DIR__ . '/touch.preload', 'DDTrace classes NOT used in preload');
