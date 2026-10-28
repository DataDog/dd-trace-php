--TEST--
FFE evaluation count configuration preserves the legacy alias and prefers the canonical name
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open')) die('skip: proc_open required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$cases = array(
    'default' => array(null, null),
    'canonical enabled' => array('true', null),
    'canonical disabled' => array('false', null),
    'legacy enabled' => array(null, 'true'),
    'legacy disabled' => array(null, 'false'),
    'canonical enabled wins' => array('true', 'false'),
    'canonical disabled wins' => array('false', 'true'),
    'invalid canonical' => array('invalid', 'true'),
    'empty canonical' => array('', 'true'),
);
foreach ($cases as $name => $values) {
    foreach (array('DD_FEATURE_FLAGS_EVALUATION_COUNTS_ENABLED', 'DD_FLAGGING_EVALUATION_COUNTS_ENABLED') as $i => $key) {
        putenv($values[$i] === null ? $key : $key . '=' . $values[$i]);
    }
    $process = ffeTestProcess(__DIR__ . '/stubs/flag_evaluation_config_app.php', $pipes);
    fclose($pipes[0]);
    $exit = proc_close($process);
    echo $name . ': ' . $exit . "\n";
    echo stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
}
?>
--EXPECT--
default: 0
bool(true)
bool(true)
canonical enabled: 0
bool(true)
bool(true)
canonical disabled: 0
bool(false)
bool(false)
legacy enabled: 0
bool(true)
bool(true)
legacy disabled: 0
bool(false)
bool(false)
canonical enabled wins: 0
bool(true)
bool(true)
canonical disabled wins: 0
bool(false)
bool(false)
invalid canonical: 0
bool(false)
bool(false)
empty canonical: 0
bool(false)
bool(false)
