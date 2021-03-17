#!/bin/bash -e

# Example: expect-phpunit-failure path/to/phpunit/test/file testMethodName

fileName=$1
testName=$2

if vendor/bin/phpunit --atleast-version 6; then
    exceptionClass="PHPUnit\\\Framework\\\ExpectationFailedException"
else
    exceptionClass="PHPUnit_Framework_ExpectationFailedException"
fi

sed -i "s/^.*public function $testName(/    \/** @expectedException $exceptionClass *\/ public function $testName(/" $fileName
