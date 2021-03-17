#!/bin/bash -xe

expect-phpunit-failure tests/Exception/RequestExceptionTest.php testCreatesExceptionWithoutPrintableBody
