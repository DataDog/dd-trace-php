### Motivation

While working on perfomance improvements we want to know how the current head compares to other scenarios, such us: current master, a specific release, no tracer at all.

The objective of this tool is to generate a callgrind file for a simple Laravel request in all the aforementioned scenarios in order to inspect them with a tool such as qcachegrind.

### Usage

From this directory :

    # Build all images
    make build

    # Edit the env appropriately in the .env file (you can use .env.example as a template)
    XDEBUG_ENABLE_PROFILER=1
    DD_TRACE_ENABLED=true
    DD_TRACE_DEBUG=false

    # Start the env
    make start_env

    # Execute request to Laravel 5.7 app via php-fpm
    make request_l57_(notracer|master|release|head)

    # Execute a request to the Laravel 5.7 app multiple times and outputs results
    make time_l57_(notracer|master|release|head)

    # Execute request to synthetic script via php-fpm
    make request_synthetic_(notracer|master|release|head)

    # Execute ONLY dd request init hook with profiling (if enabled in .env)
    make request_hook_(notracer|master|release|head)

    # Execute our init hook in multitime and outputs results
    make time_hook_(notracer|master|release|head)

    # Open a shell into the container
    make shell_(notracer|master|release|head)

Profile output is dumped into `./callgrind-files`.

Generated files containing profiling info are named repectively:
    - `callgrind.<timestamp>.notracer`
    - `callgrind.<timestamp>.master`
    - `callgrind.<timestamp>.head`
    - `callgrind.<timestamp>.release`

A tool like kcachegrind or qcachegrind is required to inspect the profiling output.
