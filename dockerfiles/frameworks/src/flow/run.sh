#!/bin/bash -xe
switch_php 7.1

mysql -h mysql -e 'CREATE DATABASE IF NOT EXISTS flow_functional_testing;'

bin/phpunit --colors -c Build/BuildEssentials/PhpUnit/UnitTests.xml
bin/phpunit --colors --stop-on-failure -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml --testsuite "Framework tests"
