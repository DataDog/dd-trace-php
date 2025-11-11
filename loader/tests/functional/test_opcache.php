<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();
skip_if_over_php85();
skip_if_opcache_missing();

$output = runCLI(__DIR__.'/fixtures/opcache.php');
assertEquals($output, <<<EOS
opcache: NO
ddtrace (Zend ext): YES
ddtrace (ext): YES
dd_library_loader: YES
EOS
);

// Loader loaded before opcache
$output = runCLI('-dzend_extension='.getLoaderAbsolutePath().' -dzend_extension=opcache '.__DIR__.'/fixtures/opcache.php', false);
assertEquals($output, <<<EOS
opcache: YES
ddtrace (Zend ext): YES
ddtrace (ext): YES
dd_library_loader: YES
EOS
);

// Loader loaded after opcache
$output = runCLI('-dzend_extension=opcache -dzend_extension='.getLoaderAbsolutePath().' '.__DIR__.'/fixtures/opcache.php', false);
assertEquals($output, <<<EOS
opcache: YES
ddtrace (Zend ext): YES
ddtrace (ext): YES
dd_library_loader: YES
EOS
);
