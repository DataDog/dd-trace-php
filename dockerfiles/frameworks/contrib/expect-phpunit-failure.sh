#!/bin/bash -xe

# Example: expect-phpunit-failure path/to/phpunit/test/file testMethodName

fileName=$1
testName=$2

sed -i "s/public function $testName(/\/** @expectedException PHPUnit\\\Framework\\\ExpectationFailedException *\/ public function $testName(/" $fileName
