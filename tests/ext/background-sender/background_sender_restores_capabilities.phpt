--TEST--
background sender restores effective capabilities from permitted set
--DESCRIPTION--
The effective set may be cleared, e.g. when prctl(PR_SET_KEEPCAPS), followed by setuid(2) has been used.
Hence we exec() ourselves on top of a process with no effective capabilities.
--SKIPIF--
<?php if (PHP_OS != "Linux") die('skip: Linux specific test for capabilities(7)'); ?>
<?php if (!extension_loaded("ffi")) die("skip: requires ext/ffi"); ?>
<?php if (posix_getuid() != 0 && getenv("ZEND_DONT_UNLOAD_MODULES")) die("skip: detected ZEND_DONT_UNLOAD_MODULES - the test is most likely executed as non-root via valgrind"); ?>
<?php if (posix_getuid() != 0 && trim(shell_exec("sudo echo 1")) !== "1") die("skip: user is not root and has no password-less sudo"); ?>
--FILE--
<?php

if (posix_getuid() != 0) {
    $sudoPath = trim(`which sudo`);
    $cmdAndArgs = explode("\0", file_get_contents("/proc/" . getmypid() . "/cmdline"));
    pcntl_exec($sudoPath, [-2 => '-E', -1 => '--'] + $cmdAndArgs);
}

$ffi = FFI::cdef(<<<DEFS
int setgroups(size_t size, const uint32_t *list);

typedef struct {
    uint32_t version;
    int pid;
} cap_user_header_t;

typedef struct {
    uint32_t effective;
    uint32_t permitted;
    uint32_t inheritable;
} cap_user_data_t;

int capset(cap_user_header_t *hdrp, const cap_user_data_t *datap);
DEFS
, "libc.so.6");

const _LINUX_CAPABILITY_VERSION_1 = 0x19980330;
const CAP_SETGID = 6;

$capheader = $ffi->new("cap_user_header_t");
$capheader->version = _LINUX_CAPABILITY_VERSION_1;

$capdata = $ffi->new("cap_user_data_t");
$capdata->inheritable = 0;
$capdata->effective = 0;
$capdata->permitted = 1 << CAP_SETGID;

if (!getenv("BACKGROUND_SENDER_RESTORES_CAPABILITIES")) {
    $ffi->capset(FFI::addr($capheader), FFI::addr($capdata));

    putenv("BACKGROUND_SENDER_RESTORES_CAPABILITIES=1");
    $cmdAndArgs = explode("\0", file_get_contents("/proc/" . getmypid() . "/cmdline"));
    pcntl_exec(array_shift($cmdAndArgs), $cmdAndArgs);

    die("exec failed?");
}

$capdata->effective = $capdata->permitted;
$ffi->capset(FFI::addr($capheader), FFI::addr($capdata));

$groups = $ffi->new("uint32_t");
$groups->cdata = 1;
var_dump($ffi->setgroups(1, FFI::addr($groups)));

// payload = [[]]
$payload = "\x91\x90";

var_dump(dd_trace_send_traces_via_thread(1, [], $payload));

echo "Done.";
?>
--EXPECT--
int(0)
bool(true)
Done.
