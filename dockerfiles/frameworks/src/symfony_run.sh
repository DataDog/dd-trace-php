#!/bin/bash -xe
php /home/symfony/phpunit --exclude-group tty,benchmark,intl-data,legacy | tee output.txt
grep "OK, but incomplete, skipped, or risky tests!" output.txt
