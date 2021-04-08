# Overview

This file explains why we decided to disable specific PHP language tests. Investigations for tests disabled before this file was created are not present.

## `ext/openssl/tests/bug54992.phpt`

Disabled on versions: `5.4`, `5.5`.

Links to sample broken executions: [5.4](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375326), [5.5](https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/5511/workflows/3d3921f6-bcfc-4975-9a8f-3b8db6005462/jobs/375320).

_Investigation_

This test started to fail after we [enabled the pcntl extension](https://github.com/DataDog/dd-trace-ci/pull/34/files) in our buster containers.

It was [skipped before](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L6) because function `pcntl_fork` was not available.

Building again the container without `pcntl` enabled AND not even building the tracer, the test still fails. Possibly the reason is that we need an ssh server listening internally on [port 64321](https://github.com/php/php-src/blob/bcd100d812b525c982cf75d6c6dabe839f61634a/ext/openssl/tests/bug54992.phpt#L13). Configuring the openssh server to run even this last test is neyond the scope of our language tests.
