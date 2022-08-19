--TEST--
background sender survives setuid
--DESCRIPTION--
setuid() will reset the effective capabilities of the thread to zero when it's run. Ensure that we do not crash afterwards.
To test this we will issue a setgroups() via the libc wrapper (which distributes the setgroups() syscall to all threads of the process).
--SKIPIF--
<?php if (PHP_OS != "Linux") die('skip: Linux specific test for setuid(2) behaviour'); ?>
<?php if (!extension_loaded("ffi")) die("skip: requires ext/ffi"); ?>
<?php if (posix_getuid() != 0 && getenv("ZEND_DONT_UNLOAD_MODULES")) die("skip: detected ZEND_DONT_UNLOAD_MODULES - the test is most likely executed as non-root via valgrind"); ?>
<?php if (getenv("SKIP_ASAN")) die("skip leak sanitizer crashes"); ?>
<?php if (posix_getuid() != 0 && trim(shell_exec("sudo echo 1")) !== "1") die("skip: user is not root and has no password-less sudo"); ?>
--ENV--
DD_TRACE_RETAIN_THREAD_CAPABILITIES=1
--FILE--
<?php

if (posix_getuid() != 0) {
    $sudoPath = trim(`which sudo`);
    $cmdAndArgs = explode("\0", file_get_contents("/proc/" . getmypid() . "/cmdline"));
    pcntl_exec($sudoPath, [-2 => '-E', -1 => '--'] + $cmdAndArgs);
}

$ffi = FFI::cdef(<<<DEFS
int setgroups(size_t size, const uint32_t *list);
int setuid(uint32_t uid);

typedef struct {
    uint32_t version;
    int pid;
} cap_user_header_t;

typedef struct {
    uint32_t effective;
    uint32_t permitted;
    uint32_t inheritable;
} cap_user_data_t;

int prctl(int option, unsigned long arg2);
int capset(cap_user_header_t *hdrp, const cap_user_data_t *datap);
DEFS
, "libc.so.6");

const PR_SET_KEEPCAPS = 8;

$ffi->prctl(PR_SET_KEEPCAPS, 1);

$ffi->setuid(1); // daemon user

const _LINUX_CAPABILITY_VERSION_1 = 0x19980330;
const CAP_SETGID = 6;

$capheader = $ffi->new("cap_user_header_t");
$capheader->version = _LINUX_CAPABILITY_VERSION_1;

$capdata = $ffi->new("cap_user_data_t");
$capdata->inheritable = 0;
$capdata->effective = $capdata->permitted = 1 << CAP_SETGID;

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
