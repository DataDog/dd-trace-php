<?php

/**
 * Harden the stock PHP run-tests.php for the Windows `test_c` CI job.
 *
 * On Windows a per-test timeout calls proc_terminate($proc, 9), which does not
 * reliably kill the process tree; the wedged php.exe keeps an OS lock on the
 * test's <name>.php file. run-tests.php then `goto retry`s the test, re-writes
 * that file via save_text() -> file_put_contents() which returns false on the
 * locked file -> error() -> exit(1), fatally aborting the whole suite mid-run.
 *
 * This applies two surgical, anchor-asserted patches to the copy of
 * run-tests.php that phpize.bat drops into the build dir:
 *   1. taskkill /T /F the timed-out process tree before proc_terminate.
 *   2. Retry the save_text() write with a short backoff instead of aborting.
 *
 * The Windows matrix spans PHP 7.2-8.5 and run-tests.php differs between them,
 * so a missing anchor is a no-op (status quo, no regression) rather than a hard
 * error -- the in-extension hang watchdog covers every version regardless. Only
 * a genuinely ambiguous (>1) match is treated as fatal.
 */

$path = $argv[1] ?? 'run-tests.php';
if (!is_file($path)) {
    fwrite(STDERR, "patch-run-tests-windows: '$path' not found\n");
    exit(1);
}

$src = file_get_contents($path);

// Match the file's existing line endings so anchors compare and patched output
// stays consistent whether the SDK ships run-tests.php with LF or CRLF.
$crlf = strpos($src, "\r\n") !== false;

/** @return void */
function apply(string &$src, string $find, string $replace, string $label): void
{
    global $crlf;
    if ($crlf) {
        $find = str_replace("\n", "\r\n", $find);
        $replace = str_replace("\n", "\r\n", $replace);
    }
    $count = substr_count($src, $find);
    if ($count === 0) {
        echo "patch-run-tests-windows: anchor '$label' not present; skipping\n";
        return;
    }
    if ($count > 1) {
        fwrite(STDERR, "patch-run-tests-windows: anchor '$label' matched $count times; ambiguous, aborting\n");
        exit(1);
    }
    $src = str_replace($find, $replace, $src);
    echo "patch-run-tests-windows: applied '$label'\n";
}

// 1. Kill the whole process tree on timeout so the test file's lock is released
//    before the retry re-writes it.
apply(
    $src,
    <<<'PHP'
            $data .= "\n ** ERROR: process timed out **\n";
            proc_terminate($proc, 9);
PHP,
    <<<'PHP'
            $data .= "\n ** ERROR: process timed out **\n";
            $dd_status = @proc_get_status($proc);
            if (isset($dd_status['pid'])) {
                @exec('taskkill /T /F /PID ' . (int)$dd_status['pid'] . ' 2>NUL');
            }
            proc_terminate($proc, 9);
PHP,
    'timeout tree-kill'
);

// 2. Make the test-file write resilient to a briefly-held Windows lock so a
//    retry write cannot escalate to a suite-killing exit(1).
apply(
    $src,
    <<<'PHP'
    if ($filename_copy && $filename_copy != $filename && file_put_contents($filename_copy, $text) === false) {
        error("Cannot open file '" . $filename_copy . "' (save_text)");
    }

    if (file_put_contents($filename, $text) === false) {
        error("Cannot open file '" . $filename . "' (save_text)");
    }
PHP,
    <<<'PHP'
    $dd_write = static function (string $f, string $t): bool {
        for ($i = 0; $i < 30; $i++) {
            if (file_put_contents($f, $t) !== false) {
                return true;
            }
            usleep(100000);
        }
        return false;
    };

    if ($filename_copy && $filename_copy != $filename && !$dd_write($filename_copy, $text)) {
        error("Cannot open file '" . $filename_copy . "' (save_text)");
    }

    if (!$dd_write($filename, $text)) {
        error("Cannot open file '" . $filename . "' (save_text)");
    }
PHP,
    'save_text retry'
);

file_put_contents($path, $src);
echo "patch-run-tests-windows: patched $path\n";
