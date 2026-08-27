<?php

// Locates the repository root so the tests can load the Drupal integration straight from
// src/, without depending on datadog.trace.sources_path pointing at the working tree.
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
