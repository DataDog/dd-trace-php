<?php

function withRequestReplayerStateLock(string $lockFile, callable $callback)
{
    $lock = fopen($lockFile, 'c');
    if ($lock === false) {
        throw new RuntimeException('Failed to open request-replayer state lock');
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Failed to lock request-replayer state');
    }

    try {
        return $callback();
    } finally {
        fclose($lock);
    }
}
