# Overview

This file explains why we decided to disable specific PHP language tests. Investigations for tests disabled before this file was created are not present.

---

# Categories of tests

## Object/resource ID skips

The following tests are marked as skipped due to the test relying on a hard-coded resource ID. All of these IDs change when the PHP tracer is enabled due to the resources created in the `ddtrace.request_init_hook`.

- `ext/sockets/tests/socket_create_pair.phpt`
- `ext/standard/tests/filters/bug54350.phpt`
- `Zend/tests/type_declarations/scalar_return_basic_64bit.phpt`
- `Zend/tests/weakrefs/weakmap_basic_map_behaviour.phpt`

## Random port selection

Many tests choose a random port to start up a service. Many of these tests have been updated to not used a random port in more recent PHP versions, but we skip these tests in older versions of PHP because they often choose a port that is already in use in CI.

- `ext/sockets/tests/socket_connect_params.phpt` ([Fixed](https://github.com/php/php-src/commit/3e9dac2) in PHP 7.4)

## Fail even with no tracer installed

The following tests fail even when the tracer is not installed.

- `ext/mcrypt/tests/bug67707.phpt` (PHP 7.1 only)
- `ext/mcrypt/tests/bug72535.phpt` (PHP 7.1 only)
- `ext/standard/tests/streams/stream_context_tcp_nodelay_fopen.phpt` (PHP 7.1+)

## Deep call stacks (PHP 5)

On PHP 5, certain tests can have intermittently deep call stacks that are deep enough to trigger the warning: `ddtrace has detected a call stack depth of 512`.

- `Zend/tests/bug54268.phpt`

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

---

# Specific tests

## `Zend/tests/object_gc_in_shutdown.phpt`, `Zend/tests/bug81104.phpt`

Tests memory limits, which we exceed due to tracer being loaded.

## `ext/curl/tests/bug76675.phpt`, `ext/curl/tests/bug77535.phpt`

Test does http request to shut down server.

## `ext/pcntl/tests/pcntl_unshare_01.phpt`

Disabled on versions: `7.4` (it wasn't there on [7.3-](https://github.com/php/php-src/tree/PHP-7.3/ext/pcntl/tests)).

Links to sample broken executions: [7.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5532/workflows/2d26f68b-fb78-46f1-b846-c9e0f1c5cefc/jobs/377363).

_Investigation_

`unshare` requires processes [not to be threaded](https://man7.org/linux/man-pages/man2/unshare.2.html) to use the flag `CLONE_NEWUSER`. In our case the background sender is started in a thread and causes the `CLONE_NEWUSER` to be flagged as an error.
Disabling the creation of the background sender thread the test passes (with a delay of 5 seconds).

## `ext/pcntl/tests/pcntl_unshare_03.phpt`

See `ext/pcntl/tests/pcntl_unshare_01.phpt`.

## `ext/openssl/tests/bug54992.phpt`

Disabled on versions: `5.4`, `5.5`.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375326), [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375320).

_Investigation_

This test started to fail after we [enabled the pcntl extension](https://github.com/DataDog/dd-trace-ci/pull/34/files) in our buster containers.

It was [skipped before](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L6) because function `pcntl_fork` was not available.

Building again the container without `pcntl` enabled AND not even building the tracer, the test still fails. Possibly the reason is that we need an ssh server listening internally on [port 64321](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L13). Configuring the openssh server to run even this last test is neyond the scope of our language tests.

## `ext/openssl/tests/bug64802.phpt`, `ext/openssl/tests/openssl_error_string_basic.phpt`

Tests do not work on PHP 5 with openssl 1.0.2.

## `ext/openssl/tests/openssl_x509_checkpurpose_basic.phpt`

Depends on an expired cert. Was fixed in [php-src/98175fc](https://github.com/php/php-src/commit/98175fc).

## `ext/ftp/tests`

Disabled on versions less than 8.1, which switches to ephemeral ports.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5626/workflows/6f8ee4d1-f2dd-4465-b3a0-44f8fde1a18f/jobs/396668/tests#failed-test-0).

_Investigation_

Such tests were skipped before and are incredibly unstable on 5. It might be a CI configuration or something, but they are unstable without the extension as well and it was decided not to spend any more time on these, for now.

## `ext/ftp/tests/005.phpt`

Disabled on versions: `5.4`, `5.5`.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5515/workflows/b1b283f3-70ab-4fc8-b142-909f4668515b/jobs/376256), [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5515/workflows/b1b283f3-70ab-4fc8-b142-909f4668515b/jobs/376255).

_Investigation_

This test started to fail after we [enabled the pcntl extension](https://github.com/DataDog/dd-trace-ci/pull/34/files) in our buster containers.

It was [skipped before](https://github.com/php/php-src/blob/29ac2c59a49e0ca9d6a5399a49f4fd1afb058fa3/ext/ftp/tests/skipif.inc#L3) because function `pcntl_fork` was not available.

The reason happens only in CI, not locally and it happens regardless of tracer being installed or not. It seems related to how routing is done in CI with the local network is shared (e.g. agent running in different container reachable via `127.0.0.1`). The conflict is due to the fact that the ftp servers launched by different suites have comnflicting ports.

## `ext/iconv/tests/iconv_basic_001.phpt`

This test has a broken `--SKIPIF--` section that was [fixed in PHP 7.0](https://github.com/php/php-src/commit/c71cd8f).

```bash
# Running on the new Buster PHP 5.5 container:
$ php -r 'var_dump(setlocale(LC_ALL, "en_US.utf8"));'
bool(false)
```

## `ext/posix/tests/posix_errno_variation2.phpt`

This test was flaky until it was [fixed in PHP 7.2](https://github.com/php/php-src/commit/f4474e5).

## `ext/standard/tests/streams/proc_open_bug69900.phpt`

* Disabled on versions: `7.0+`.
* [Broken CI build example](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5558/workflows/0f25c071-6f6c-4d83-b075-536f6a63369e/jobs/382667)
* This test has [a long history of being flaky in CI](https://github.com/php/php-src/commits/master/ext/standard/tests/streams/proc_open_bug69900.phpt).

## `sapi/cli/tests/017.phpt`

Disabled on versions: `5.4`, `5.5`, `5.6`.

Links to sample broken executions: [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5610/workflows/b7836d18-ba47-4315-9cd2-4c1749c0e984/jobs/393611), [5.6](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5610/workflows/b7836d18-ba47-4315-9cd2-4c1749c0e984/jobs/393616).

_Investigation_

The test fails on new buster containers because we copy on 5.x the `php-development.ini` that enables `log_errors = On`.
This causes this test to print an extra line (the logged error) and to fail. The failure happen without the tracer installed as well.

## `ext/sockets/tests/socket_create_listen-nobind.phpt`

* Disabled on versions: `5.4 --> 7.3`.
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
