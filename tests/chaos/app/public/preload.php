<?php

require_once __DIR__ . '/../src/Controller.php';
\opcache_compile_file(__DIR__ . '/../src/Service.php');
// require_once __DIR__ . '/../src/Snippets.php'; <-- we leave at least one file not preloaded.
\opcache_compile_file(__DIR__ . '/index.php');
