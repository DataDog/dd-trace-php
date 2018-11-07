#!/usr/bin/env bash

set -e

cd tests/Integration/Frameworks/Laravel/$VERSION

./vendor/bin/phpunit $*
