### Motivation

While working on perfomance improvements we want to know how the current head compares to other scenarios, such us: current master, a specific release, no tracer at all.

The objective of this tool is to generate a callgrind file for a simple Laravel request in all the aforementioned scenarios in order to inspect them with a tool such as qcachegrind.

### Usage

From the project root directory :

    docker-compose up -d overhead-nginx

    # In order to rebuild all the images

    docker-compose build overhead-nginx overhead-php-fpm-notracer overhead-php-fpm-master overhead-php-fpm-head overhead-php-fpm-release

    # no tracer
    curl localhost:8886

    # current master on GitHub
    curl localhost:8887

    # current local head
    curl localhost:8888

    # a specific release, set DD_TRACER_LIBRARY_VERSION=X.Y.Z in service 'overhead-php-fpm-release'
    curl localhost:8889

Profile output is dumped into `./tests/overhead/callgrind-files`.

Generated files containing profiling info are named repectively:
    - `callgrind.<timestamp>.notracer`
    - `callgrind.<timestamp>.master`
    - `callgrind.<timestamp>.head`
    - `callgrind.<timestamp>.release`

A tool like kcachegrind or qcachegrind is required to inspect the profiling output.
