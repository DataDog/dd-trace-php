<?php

$response = require __DIR__ . '/bootstrap.php';

$response->assertSameFromKey('ddtrace_installed', false);

// No news is good news
