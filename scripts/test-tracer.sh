#!/usr/bin/env bash

make -f DDMakefile test_integration PHPUNIT="$*"
