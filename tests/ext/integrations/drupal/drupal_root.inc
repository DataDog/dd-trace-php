<?php

// Load the integration straight from src/, without relying on datadog.trace.sources_path.
function dd_drupal_tracer_root()
{
    $dir = __DIR__;
    while (!is_file($dir . '/src/DDTrace/Integrations/Drupal/DrupalIntegration.php')) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            return false;
        }
        $dir = $parent;
    }
    return $dir;
}
