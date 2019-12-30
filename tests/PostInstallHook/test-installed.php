<?php

$response = require __DIR__ . '/bootstrap.php';

$response->assertSameFromKey('ddtrace_installed', true);

// No news is good news
