#!/bin/bash
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../" >/dev/null && pwd )"

IMAGE=$1

if [[ -z $IMAGE ]]; then
    IMAGE=circleci/php:5.6
else
    shift 1
fi

if [[ "$#" == "0" ]]; then
    docker run --rm -v "${ROOT}":/home/circleci/src -w /home/circleci/src -i -t "$IMAGE" bash -c "phpize --clean; phpize && ./configure && make CFLAGS='-Wall -Werror -Wextra' clean test TESTS='-q'"
else
    docker run --rm -v "${ROOT}":/home/circleci/src -w /home/circleci/src -i -t "$IMAGE" "$@"
fi
