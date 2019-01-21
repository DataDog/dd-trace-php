<?php

namespace DDTrace\Bridge;

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

require_once __DIR__ . '/functions.php';

if (!dd_tracing_enabled()) {
    return;
}

Bootstrap::tracerOnce();
IntegrationsLoader::load();
