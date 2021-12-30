--TEST--
Test that the helper is launched with the correct properties
--SKIPIF--
<?php
include __DIR__ . '/inc/check_extension.php';
if (key_exists('USE_ZEND_ALLOC', $_ENV) && $_ENV['USE_ZEND_ALLOC'] == '0') {
    die('skip not to be run with valgrind (does not get adopted by init)');
}
check_extension('posix');
check_extension('pcntl');
check_extension('sockets');
?>
--INI--
ddappsec.helper_log_file=/tmp/helper_test.log
ddappsec.helper_launch=1
ddappsec.log_file=/tmp/php_appsec_test.log
ddappsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{set_helper_path,set_helper_extra_args,is_connected_to_helper,helper_mgr_acquire_conn};

if (key_exists('TEST_PHP_EXECUTABLE', $_ENV)) {
    set_helper_path($_ENV['TEST_PHP_EXECUTABLE']);
} else {
    set_helper_path(readlink('/proc/self/exe'));
}
$ext_dir = ini_get('extension_dir');
$ext_args = array();
foreach(array('posix', 'pcntl', 'sockets') as $ext) {
    if (file_exists("$ext_dir/$ext.so")) {
        $ext_args[] = '-d';
        $ext_args[] = "extension=$ext.so";
    }
}
$helper_args = implode(' ', array_merge(
    array(
        '-n',
        '-d', 'variables_order=EGPCS',
        '-d', 'extension_dir=' . $ext_dir
    ),
    $ext_args,
    array(
        '-d', 'display_errors=1',
        '-d', 'error_reporting=2147483647',
        __DIR__ . "/inc/helper_invocation.php",
        phpversion('ddappsec')
    )));
set_helper_extra_args($helper_args);

echo 'helper_mgr_acquire_conn run', "\n";
var_dump(helper_mgr_acquire_conn());
echo "\n";

echo 'Connected?', "\n";
var_dump(is_connected_to_helper());
echo "\n";

echo 'Contents of helper log:', "\n";
echo file_get_contents('/tmp/helper_test.log');
?>
--EXPECTF--
helper_mgr_acquire_conn run
bool(true)

Connected?
bool(true)

Contents of helper log:
[%s] pre-exec: Going for second fork
[%s] pre-exec: Intermediate process exiting
[%s] pre-exec: About to call execv
Checking open file descriptors
* has file descriptor 0
* has file descriptor 1
* has file descriptor 2
Checking procmask
array (
)
Checking umask
0
Checking parent uid (should be 1)
1
Checking process group id == pid (is a process group leader)
OK
Checking session id == pid (is a session leader)
OK

Checking socket id
file descriptor is %d
opened fd %d

Accepting a connection:
accepted a new connection
read initial message from extension (size %d)
read remaining data
