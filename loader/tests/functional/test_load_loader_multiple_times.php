<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-dzend_extension='.getLoaderAbsolutePath().' '.__DIR__.'/fixtures/ddtrace.php', true, ['DD_TRACE_DEBUG=1']);

// PHP warning
assertContains($output, 'Cannot load dd_library_loader');
// dd_library_loader warning
assertContains($output, 'dd_library_loader has been loaded multiple times, aborting');
