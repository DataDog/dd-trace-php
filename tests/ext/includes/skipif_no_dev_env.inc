<?php

/* Some tests depend on external services that are provided by the development
 * environment such as the 'request-replayer'. These tests should be skipped
 * when run outside of the Datadog development environment.
 *
 * https://github.com/DataDog/dd-trace-php/issues/1214
 */

if (getenv('DATADOG_HAVE_DEV_ENV') !== '1') die('skip: Test has development environment dependencies');
