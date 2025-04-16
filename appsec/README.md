# Datadog PHP Application Security

Datadog Application Security (AppSec) extension for PHP. 

## Installation

To install the Datadog AppSec extension, first download the [installer](https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php) from the Datadog Tracer repository. 

```
$ wget https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php -O datadog-setup.php
```
:exclamation: This extension requires version `7.0` or above of [PHP](https://php.net).

### Online Installation 

This installation requires an active internet connection and the installer mentioned above, it will automatically find and download the required versions from Github and install them in your system. 

To install both the Tracer and AppSec extensions, use the following command:
```
$ php datadog-setup.php --php-bin all --enable-appsec
```

### Offline Installation

The offline installation provides a way to install an existing archive, these can be found in the [Tracer release section](https://github.com/DataDog/dd-trace-php/releases) and are named `dd-library-php-<version>-<arch>-<os>-<libc>.tar.gz`.

To install both the Tracer and AppSec extension, use the following command, please note that the archive names might differ:
```
$ php datadog-setup.php --php-bin all --enable-appsec --file dd-library-php-0.72.0-x86_64-linux-gnu.tar.gz
```

### Verifying the installation

Once the installation has concluded, the best way to verify that the extensions have been correctly installed is by checking `phpinfo()` on your application(s) and looking at the relevant sections for each of the extensions. 

### Configuration

After the installation has been completed, the AppSec extension will be loaded and enabled. If the `--enable-appsec` option was not passed to the installer, enabling the extension can be done through the `ini` settings or through environment variables.

To enable the extension using `ini` settings, find the extension's `ini` file, which can usually be found in  `/etc/php/<version>/xxx/conf.d/98-ddtrace.ini` but may differ depending on your installation. Consult the top of the output of `phpinfo()` to identify the directory that is scanned for `.ini` files, if any. Once the settings file has been found, locate, uncomment and set the following variable:
```
datadog.appsec.enabled = true
```
To enable the extension using the environment, make sure `DD_APPSEC_ENABLED=true` is exported on the environment of the PHP runtime. Exporting this environment variable to the runtime will depend on your installation.

## Development

### Requirements

The following packages are required:
* `php`
* `cmake`
* `git`
* `python`
* `clang-tidy`
* `clang-format`
* `curl`

### Building the extension & helper

This project requires cmake to build both the extension and helper, so building the main components is as simple as running the following commands in the root of the repository:

First of all, it is required to initialize all submodules on the repository. If you haven´t done so yet, run:

```
git submodule update --init --recursive
````

Once you have all the submodules initialized run the following commands in the appsec directory:

```
mkdir build
cd build
cmake ..
make -j
```
This will produce the extension, `ddappsec.so` and the helper library `libddappsec-helper.so`.

Alternatively, to build the extension but not the helper, you can disable the helper build on the cmake step:
```
cmake .. -DDD_APPSEC_BUILD_HELPER=OFF
```
Similarly, to build the helper but not the extension:
```
cmake .. DDD_APPSEC_BUILD_EXTENSION=OFF
```

#### Testing the extension

Extension tests can be located in the `dd-appsec-php/tests/extension` directory and they consist of `phpt` units. To execute these tests, run the following command in the build directory:
```
make xtest TESTS="--show-diff"
```
If any test fails, a number of files will be produced in the `dd-appsec-php/tests/extension`, such as logs, diffs, etc. Alternatively, to execute just a single test, add the test or tests to the `TESTS` variable:
```
make xtest TESTS="--show-diff ../tests/extension/rinit_body_multipart.phpt"
```
To execute the tests with memcheck (requires valgrind), use the following command:
```
make xtest TESTS="--show-diff --show-mem -m"
```
#### Testing the helper

Helper tests can be located in the `dd-appsec-php/tests/helper` directory, these consist of a set of C++ unit tests written using Google Test and Mock. To build the helper tests, run the following command in the build directory:
```
make ddappsec_helper_test
```
And run the tests by executing the following command, again from the build directory:
```
./tests/helper/ddappsec_helper_test
```
To test the helper with the address and leak sanitizer, you will need to execute the cmake step with a few other options as shown below (note that it's not strictly necessary to disable the extension):
```
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF \
         -DCMAKE_CXX_FLAGS="-fsanitize=address -fsanitize=leak -DASAN_BUILD" \
         -DCMAKE_C_FLAGS="-fsanitize=address -fsanitize=leak -DASAN_BUILD" \
         -DCMAKE_EXE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" \
         -DCMAKE_MODULE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak"
```
After this step has concluded, build and run the helper test as before, if the sanitisers detect anything of relevance, extra output will be produced.

### Linting

As part of our workflow, we use `clang-tidy` to lint both the extension and helper, in order to enable it add `-DDD_APPSEC_ENABLE_CLANG_TIDY=ON` to the cmake step and after building you should be able to lint by running `make tidy`.

## Contributing

Before contributing to this open source project, read our [contributing guide](CONTRIBUTING.md) and [coding conventions](CONVENTIONS.md).

## License

Unless explicitly stated otherwise all files in this repository are dual-licensed under the [Apache-2.0 License](LICENSE.Apache) or [BSD-3-Clause License](LICENSE.BSD3).

## Copyright
```
This product includes software developed at Datadog (https://www.datadoghq.com/).

Copyright 2021 Datadog, Inc.
```
`git log --all | grep 'Author' | sort -u` for a list of contributors.
