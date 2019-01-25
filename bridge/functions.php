<?php

function dd_tracing_enabled()
{
    $value = getenv('DD_TRACE_ENABLED');
    if (false === $value) {
        // Not setting the env means we default to enabled.
        return true;
    }

    $value = trim(strtolower($value));
    if ($value === '0' || $value === 'false') {
        return false;
    } else {
        return true;
    }
}

/**
 * Checks if any of the provided classes exists.
 *
 * @param string[] $sentinelClasses
 * @return bool
 */
function any_class_exists(array $sentinelClasses)
{
    foreach ($sentinelClasses as $sentinelClass) {
        if (class_exists($sentinelClass)) {
            return true;
        }
    }

    return false;
}
