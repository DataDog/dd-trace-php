# A dogstatsd client written in C

This is a [dogstatsd](https://docs.datadoghq.com/developers/dogstatsd/) client written in C. It's used by the
[PHP tracer](https://github.com/DataDog/dd-trace-php), and right now only implements the pieces needed for its metrics
logging. If there is demand, it can be split into its own project.

Requirements:
  - POSIX OS
  - C99 compatible compiler and standard library, notably `snprintf`.

Optional, but recommended:
  - CMake 3.10 or newer.

The build has been tested on recent Mac OS and Linux operating systems.

## Compiling

This project intentionally uses a simple layout, with just `client.c` and `dogstatsd_client/client.h`. You can build,
compile, and install these however you want as long as you use a C99 compiler; they do not require any configuring.
Building with CMake does provide pkg-config and CMake project integration, though.

### Compiling and installing with CMake

This project has CMake support, which will generate files for `pkg-config` and cmake imported targets. It should use an
out of tree build, meaning don't build it from the directory that contains CMakeLists.txt.

Here are some common commands that will probably get it built and installed on your platform, where `$prefix` is the
path CMake will install to and `$srcdir` is the directory that contains this project's `CMakeLists.txt`:
```
mkdir /tmp/build-dogstatsd_client
cd /tmp/build-dogstatsd_client
cmake -DCMAKE_BUILD_TYPE=RelWithDebInfo -DCMAKE_INSTALL_PREFIX="$prefix" $srcdir
cmake --build . --config RelWithDebInfo
cmake --build . --target install
```

To build the dogstatsd_client tests, add `-DBUILD_TESTING=ON` to the cmake flags and use the `test` target.

## Integrating from other projects
If dogstatsd_client was installed via CMake then there is support for `pkg-config` and CMake.

The header is named `dogstatsd_client/client.h`. The [API](#API) is described below.

### Integrating with pkg-config

The path `$prefix/share/pkgconfig` will contain the `dogstatsd_client.pc` file. If it is added to `PKG_CONFIG_PATH`,
then commands like `pkg-config --libs dogstatsd_client` should work.

### Integrating with CMake
As long as `$prefix` is in the environment variable `CMAKE_PREFIX_PATH`, e.g.
`export CMAKE_PREFIX_PATH="$prefix:$CMAKE_PREFIX_PATH"` in bash, then `find_package(dogstatsd_client)` should find it.
The target `DogstatsdClient::DogstatsdClient` can then be linked. As an example, this will add the client to the
`example` target:

```
find_package(dogstatsd_client REQUIRED)
target_link_libraries(example DogstatsdClient::DogstatsdClient)
```

## API

Create a client with `dogstatsd_client_ctor`. Note that the `const_tags` parameter will be attached to all metrics
automatically. This example uses localhost and the default port.
```c
char buf[DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE];
size_t len = DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE;
dogstatsd_client client;
if (dogstatsd_client_ctor(&client, "localhost", "8125", buf, len, "lang:php") == 0) {
  // success
}
```

To increment a metric with the default sampling rate of 1.0, use `dogstatsd_client_count`. This example increments the
metric `datadog.tracer.uncaught_exceptions` by 1, using the tag `class:sigsegv`:
```c
dogstatsd_client_count(&client, "datadog.tracer.uncaught_exceptions", "1", "class:sigsegv");
```
The tags parameter can be NULL or an empty string if you do not need to set any tags specific to this metric.

Use the `dogstatsd_client_dtor` function to clean up. Note that it will _not_ free any arguments it has been passed;
it will clean up only its own data.
```c
dogstatsd_client_dtor(&dtor);
````
The dtor function does not need to be called if the ctor fails.
