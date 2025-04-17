<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

function autoload(string $class): void
{
    $prefix = 'FakeApp\\FakeRoute\\';
    $base_dir = __DIR__ . '/src/';

    $len = \strlen($prefix);
    if (\strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = \substr($class, $len);

    $file = $base_dir . \str_replace('\\', '/', $relative) . '.php';

    include $file;
}
