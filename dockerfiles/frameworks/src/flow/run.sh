#!/bin/bash -xe
mysql -h mysql -e 'CREATE DATABASE IF NOT EXISTS flow_functional_testing;'

# FLOW_CONTEXT=Testing/Behat ./flow doctrine:create
bin/phpunit --colors --stop-on-failure -c Build/BuildEssentials/PhpUnit/FunctionalTests.xml --testsuite "Framework tests"
