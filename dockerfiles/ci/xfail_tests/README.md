# Overview

This file explains why we decided to disable specific PHP language tests. Investigations for tests disabled before this file was created are not present.

---

# Categories of tests

## Object/resource ID skips

The following tests are marked as skipped due to the test relying on a hard-coded resource or object ID. All of these IDs change when the PHP tracer is enabled due to the objects/resources created in the `ddtrace.request_init_hook`.

- `ext/sockets/tests/socket_create_pair.phpt`
- `ext/zip/tests/bug38943.phpt`
- `ext/zip/tests/bug38943_2.phpt`
- `Zend/tests/bug80194.phpt`

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

---

# Specific tests

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

## `ext/ftp/tests`

Disabled on versions: `5.4`, `5.5`.

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
