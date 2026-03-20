# Overview

This file explains why we decided to disable specific PHP language tests. Investigations for tests disabled before this file was created are not present.

---

# Categories of tests

## Object/resource ID skips

The following tests are marked as skipped due to the test relying on a hard-coded resource ID. All of these IDs change when the PHP tracer is enabled due to the resources created in the `datadog.trace.sources_path`.

- `ext/sockets/tests/socket_create_pair.phpt`
- `ext/standard/tests/filters/bug54350.phpt`
- `Zend/tests/type_declarations/scalar_return_basic_64bit.phpt`
- `Zend/tests/weakrefs/weakmap_basic_map_behaviour.phpt`
- `ext/standard/tests/filters/bug54350.phpt`

## Random port selection

Many tests choose a random port to start up a service. Many of these tests have been updated to not used a random port in more recent PHP versions, but we skip these tests in older versions of PHP because they often choose a port that is already in use in CI.

- `ext/sockets/tests/socket_connect_params.phpt` ([Fixed](https://github.com/php/php-src/commit/3e9dac2) in PHP 7.4)
- `ext/sockets/tests/socket_sendto_params.phpt` (PHP 7.1 only)

## Fail even with no tracer installed

The following tests fail even when the tracer is not installed.

- `ext/mcrypt/tests/bug67707.phpt` (PHP 7.1 only)
- `ext/mcrypt/tests/bug72535.phpt` (PHP 7.1 only)
- `ext/standard/tests/streams/stream_context_tcp_nodelay_fopen.phpt` (PHP 7.1+)

## Tests relying on `spl_object_id`


- `Zend/tests/gh7958.phpt` (PHP-8.1)

## `var_dump()`-ed objects with additional properties from ObjectKVStore

The following tests assert the output of `var_dump($obj)` and fail because we add the additional properties through `ObjectKVStore`.

- `ext/pdo/tests/pdo_023.phpt` PHP 7+
- `ext/pdo/tests/pdo_030.phpt` PHP 7+
- `ext/pdo_sqlite/tests/bug43831.phpt` PHP 7+
- `ext/pdo_sqlite/tests/bug44327_2.phpt` PHP 7+
- `ext/pdo_sqlite/tests/bug44327_3.phpt` PHP 7+
- `ext/pdo_sqlite/tests/bug48773.phpt` PHP 7+
- `ext/pdo_sqlite/tests/pdo_fetch_func_001.phpt` PHP 7+
- `ext/pdo_sqlite/tests/pdo_sqlite_lastinsertid.phpt` PHP 8.1

## Tests exposing loaded structures of the tracer

- `ext/spl/tests/gh10011.phpt`
- `Zend/tests/gc_045.phpt`

## Tests related to exceptions

- `Zend/tests/generators/exception_during_shutdown.phpt`
- `ext/dom/tests/dom003.phpt`
- `ext/dom/tests/dom_set_attr_node.phpt`
- `ext/intl/tests/bug60192-sort.phpt`
- `ext/phar/tests/frontcontroller29.phpt`
- `ext/phar/tests/cache_list/frontcontroller29.phpt`
- `ext/soap/tests/bug77088.phpt`
- `ext/spl/tests/gh8318.phpt`

## Tests checking for DO_ICALL

On PHP 8.0 and PHP 8.1 we override zend_execute_internal, which causes DO_FCALL instead of DO_ICALL opcodes to be emitted. Skipping some `ext/opcache` tests there.

---

# Specific tests

## `ext/standard/tests/file/file_get_contents_file_put_contents_5gb.phpt`

Disabled on versions: `8.2`, `8.3`.

Allocates > 5GB of Ram on a CircleCI medium instance (limit 4GB) but uses
`/proc/meminfo` to check if enough memory is available in the `SKIP` section.
See https://github.com/php/php-src/pull/14895 and https://github.com/DataDog/dd-trace-php/pull/2752#issuecomment-2219813169

## `sapi/cli/tests/bug80092.phpt`

Temporarily disabled due to a too strict of a check for the precise php -v output.

## `Zend/tests/object_gc_in_shutdown.phpt`, `Zend/tests/bug81104.phpt`, `Zend/tests/gh11189(_1).phpt`, `Zend/tests/gh12073.phpt`, `ext/standard/tests/gh14643_longname.phpt`

Tests memory limits, which we exceed due to tracer being loaded.

## `Zend/tests/bug63882_2.php`

By _chance_ the internal comparison happens against another GC protected array when arsort()'ing; given that we inject a couple custom global variables in our init.

## `ext/curl/tests/bug76675.phpt`, `ext/curl/tests/bug77535.phpt`

Test does http request to shut down server.

## `ext/curl/tests/curl_postfields_array.phpt`, `ext/curl/tests/curl_setopt_CURLOPT_ACCEPT_ENCODING.phpt`, `ext/curl/tests/curl_setopt_CURLOPT_DEBUGFUNCTION.phpt`

Distributed tracing headers are injected

## `ext/intl/tests/bug60192-sort.phpt`

Has a refcounting bug on PHP 7.4 (which gets triggered by the tracer, but isn't caused by it).

## `ext/pcntl/tests/pcntl_unshare_01.phpt`

Disabled on versions: `7.4` (it wasn't there on [7.3-](https://github.com/php/php-src/tree/PHP-7.3/ext/pcntl/tests)).

Links to sample broken executions: [7.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5532/workflows/2d26f68b-fb78-46f1-b846-c9e0f1c5cefc/jobs/377363).

_Investigation_

`unshare` requires processes [not to be threaded](https://man7.org/linux/man-pages/man2/unshare.2.html) to use the flag `CLONE_NEWUSER`. In our case the background sender is started in a thread and causes the `CLONE_NEWUSER` to be flagged as an error.
Disabling the creation of the background sender thread the test passes (with a delay of 5 seconds).

## `ext/pcntl/tests/pcntl_unshare_03.phpt`

See `ext/pcntl/tests/pcntl_unshare_01.phpt`.

## `ext/openssl/tests/bug74159.phpt`

Disabled on versions: `7.2`.

Caused by openssl version that was upgraded from `1.1.1d` in our pre-existing buster images to `1.1.1n` in the new buster containers. It fails even without the tracing library installed.

## `ext/openssl/tests/openssl_x509_checkpurpose_basic.phpt`

Depends on an expired cert. Was fixed in [php-src/98175fc](https://github.com/php/php-src/commit/98175fc).

## `ext/openssl/tests/tlsv1.0_wrapper.phpt`, `ext/openssl/tests/tlsv1.1_wrapper.phpt`, `ext/openssl/tests/tlsv1.2_wrapper.phpt`

Disabled on versions: `7.0`, `7.1`, `7.2`.

Caused by openssl version that was upgraded from `1.1.1d` in our pre-existing buster images to `1.1.1n` in the new buster containers. It fails even without the tracing library installed.

## `ext/ftp/tests`

Disabled on versions less than 8.1, which switches to ephemeral ports.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5626/workflows/6f8ee4d1-f2dd-4465-b3a0-44f8fde1a18f/jobs/396668/tests#failed-test-0).

_Investigation_

Such tests were skipped before and are incredibly unstable on 5. It might be a CI configuration or something, but they are unstable without the extension as well and it was decided not to spend any more time on these, for now.

## `ext/posix/tests/posix_errno_variation2.phpt`

This test was flaky until it was [fixed in PHP 7.2](https://github.com/php/php-src/commit/f4474e5).

## `ext/standard/tests/general_functions/phpinfo.phpt`

* Disabled on versions: `7.0 --> 8.1`.
* Upstream fix: [Reduce regex backtracking in phpinfo.phpt](https://github.com/php/php-src/commit/c4c45da4b96889348d86828c26225d113af14d21), which is present in PHP 8.2+.

This test compares very large `phpinfo()` output. With tracer-specific modules and CI environment variables enabled, the output grows and older `%A`/`%a` patterns in this test can hit pathological backtracking and fail nondeterministically.

## `ext/standard/tests/streams/proc_open_bug69900.phpt`

* Disabled on versions: `7.0+`.
* [Broken CI build example](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5558/workflows/0f25c071-6f6c-4d83-b075-536f6a63369e/jobs/382667)
* This test has [a long history of being flaky in CI](https://github.com/php/php-src/commits/master/ext/standard/tests/streams/proc_open_bug69900.phpt).

## `ext/sockets/tests/socket_create_listen-nobind.phpt`

* Disabled on versions: `7.0 --> 7.3`.
* [Broken CI build example](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/6016/workflows/dd24ea85-1ec4-47ea-9311-080a66d045a5/jobs/497177)

This test runs succesfully only if a socket CANNOT be created on port 80 in the environment where the test is executed. With recent changes to CircleCI is now possible to create a socket on port 80. As a proof of it, ssh-ing into a CircleCI runner:

```
$ TEST_PHP_EXECUTABLE=$(which php) php run-tests.php --show-out --show-diff ext/sockets/tests/socket_create_listen-nobind.phpt
...
001+ resource(4) of type (Socket)   <<<< dumping the returned socket [here](https://github.com/php/php-src/blob/53ea910d1760c87b6110a461f13ebe0e244c9914/ext/sockets/tests/socket_create_listen-nobind.phpt#L17), it is supposed to return `false` instead.
...
```

The reason why this test is not failing on PHP 7.4+ if because it is skipped by this [extra check](https://github.com/php/php-src/blob/9db3eda2cbaa01529d807b2326be13e7b0e5e496/ext/sockets/tests/socket_create_listen-nobind.phpt#L16-L18).

As a confirmation, running the previous test on a 7.4 runner would result in:

```
$ TEST_PHP_EXECUTABLE=$(which php) php run-tests.php --show-out --show-diff ext/sockets/tests/socket_create_listen-nobind.phpt
...
SKIP Test if socket_create_listen() returns false, when it cannot bind to the port. [ext/sockets/tests/socket_create_listen-nobind.phpt] reason: Test cannot be run in environment that will allow binding to port 80 (azure)
...
```

## `Zend/fibers/out-of-memory-in*`

ddtrace request init hook consumes more than 2 MB of memory and fails too early instead of testing what it should.

## `Zend/tests/fibers/gh10496-001.phpt`, `Zend/tests/weakrefs/gh10043-007.phpt`, `Zend/tests/fibers/destructors_005.phpt`

ddtrace affects the order of destructor execution due to creating span stacks etc.

## `Zend/tests/stack_limit/stack_limit_013.phpt`

This particular test is very close to the stack limit, and thus sometimes fails to actually exceed the stack limit with ddtrace.

## `ext/zend_test/tests/`, `Zend/tests/gh10346.phpt`

Observer tests trace all functions, including dd setup. Exclude these from being observed.

## `ext/standard/tests/network/syslog_null_byte.phpt`, `ext/standard/tests/network/syslog_new_line.phpt`

Both of them have a additional `PHPT: DIGEST-MD5 common mech free` in the output which is not expected in the test. This line originates from `libdigestmd5.so` which is shipped as part of `libsasl2-modules` in Debian. We are currently running those tests on Debian Buster which is stuck on Version 2.1.27 of that package, while the "fix" is in 2.1.28 version of the Debian package.

See also: https://github.com/DataDog/dd-trace-php/pull/2218

## SKIP\_ONLINE\_TESTS

The env var `SKIP_ONLINE_TESTS` is set so that in newer PHP versions, we skip
any test which checks this env var. Online tests are too flaky for CI.

The exact PHP version that a given test checks this env var varies, but these
are some tests which are skipped for older versions which don't check it:

 - `ext/sockets/tests/socket_shutdown.phpt`

## `Zend/tests/disable_classes_warning.phpt`

Disabled on versions: `8.5+`.

PHP 8.5 completely removed the `disable_classes` INI directive (see [RFC](https://wiki.php.net/rfc/remove_disable_classes)).

## `ext/uri/tests/whatwg/parsing/*_null_byte.phpt`

These tests use object ids, and %00; changing the EXPECT to EXPECTF will cause the %00 to be matched as literal NULL-Bytes, breaking the test.

## `ext/soap/tests/soap_qname_crash.phpt`

Disabled on versions: `8.1+`.

This test checks PHP's handling of excessively large QName prefix in SoapVar (a stress test for edge cases). With ddtrace loaded, the additional memory overhead causes the test to be killed before it can complete, due to hitting memory limits during the stress test.

