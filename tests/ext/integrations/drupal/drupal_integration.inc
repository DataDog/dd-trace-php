<?php

require_once __DIR__ . '/drupal_root.inc';

$root = dd_drupal_tracer_root();

// Declared on demand: only a mid-request class declaration exercises hook re-registration.
spl_autoload_register(function ($class) {
    if ($class === 'Drupal\Core\Template\TwigThemeEngine') {
        require __DIR__ . '/drupal_twig_engine.inc';
    }
});

spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'DDTrace\\') !== 0) {
        return;
    }
    $candidates = [
        $root . '/src/' . str_replace('\\', '/', $class) . '.php',
        // DDTrace\Tag, DDTrace\Type and friends live outside the PSR-4 tree.
        $root . '/src/api/' . substr(strrchr($class, '\\'), 1) . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require $file;
            return;
        }
    }
    echo "autoload miss: $class\n";
});

// The hook budget is per target, so $probeTargets needs an entry per install site.
function dd_drupal_render_report(array $spans, array $probeTargets)
{
    $tags = [];
    $engines = [];
    foreach ($spans as $span) {
        if ($span['name'] !== 'drupal.theme.render') {
            continue;
        }
        $file = isset($span['meta']['drupal.template.file']) ? $span['meta']['drupal.template.file'] : '<missing>';
        $tags[$file] = (isset($tags[$file]) ? $tags[$file] : 0) + 1;
        // Also reported so that a prehook exception swallowed by the sandbox cannot pass silently.
        $engine = isset($span['meta']['drupal.render.engine']) ? $span['meta']['drupal.render.engine'] : '<missing>';
        $engines[$engine] = (isset($engines[$engine]) ? $engines[$engine] : 0) + 1;
    }
    ksort($tags);
    ksort($engines);
    foreach ($engines as $engine => $count) {
        echo "drupal.render.engine[$engine] = $count\n";
    }
    foreach ($tags as $file => $count) {
        echo "drupal.template.file[$file] = $count\n";
    }

    // A leaked per-render hook shows up as an exhausted budget on its target.
    foreach ($probeTargets as $target) {
        $probe = DDTrace\install_hook($target, function () {
        });
        echo "hook budget left [$target]: " . ($probe ? "yes" : "no") . "\n";
        if ($probe) {
            DDTrace\remove_hook($probe);
        }
    }
}
