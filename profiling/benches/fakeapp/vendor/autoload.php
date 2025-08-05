<?php

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/FakeApp/FakeRoute/autoload.php';
require __DIR__ . '/FakeApp/Http/autoload.php';

spl_autoload_register('App\\autoload');
spl_autoload_register('FakeApp\\FakeRoute\\autoload');
spl_autoload_register('FakeApp\\Http\\autoload');
