#!/bin/bash -e

expect-phpunit-failure tests/Exception/RequestExceptionTest.php testCreatesExceptionWithoutPrintableBody
