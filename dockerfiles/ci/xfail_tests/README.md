# Overview

This file explains why we decided to disable specific PHP language tests. Investigations for tests disabled before this file was created are not present.

## `ext/openssl/tests/bug54992.phpt`

Disabled on versions: `5.4`, `5.5`.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375326), [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375320).

_Investigation_

This test started to fail after we [enabled the pcntl extension](https://github.com/DataDog/dd-trace-ci/pull/34/files) in our buster containers.

It was [skipped before](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L6) because function `pcntl_fork` was not available.

Building again the container without `pcntl` enabled AND not even building the tracer, the test still fails. Possibly the reason is that we need an ssh server listening internally on [port 64321](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L13). Configuring the openssh server to run even this last test is neyond the scope of our language tests.

## `ext/ftp/tests/005.phpt`

Disabled on versions: `5.4`, `5.5`.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5515/workflows/b1b283f3-70ab-4fc8-b142-909f4668515b/jobs/376256), [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5515/workflows/b1b283f3-70ab-4fc8-b142-909f4668515b/jobs/376255).

_Investigation_

This test started to fail after we [enabled the pcntl extension](https://github.com/DataDog/dd-trace-ci/pull/34/files) in our buster containers.

It was [skipped before](https://github.com/php/php-src/blob/29ac2c59a49e0ca9d6a5399a49f4fd1afb058fa3/ext/ftp/tests/skipif.inc#L3) because function `pcntl_fork` was not available.

The reason happens only in CI, not locally and it happens regardless of tracer being installed or not. It seems related to how routing is done in CI with the local network is shared (e.g. agent running in different container reachable via `127.0.0.1`). The conflict is due to the fact that the ftp servers launched by different suites have comnflicting ports.

## Object/resource ID skips

The following tests are marked as skipped due to the test relying on hard-coded resource or object ID. All of these IDs change when the PHP tracer is enabled due to the objects/resources created in the `ddtrace.request_init_hook`.

- `ext/sockets/tests/socket_create_pair.phpt`
- `ext/zip/tests/bug38943.phpt`
- `ext/zip/tests/bug38943_2.phpt`
- `Zend/tests/bug80194.phpt`
