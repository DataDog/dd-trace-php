<?php

error_reporting(E_ALL);

require __DIR__ . '/bootstrap_common.php';

require_once __DIR__ . '/Appsec/Mock.php';

ini_set("datadog.trace.auto_flush_enabled", "false");
