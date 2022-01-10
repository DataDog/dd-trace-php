# Datadog PHP Application Security

![extension](https://github.com/DataDog/dd-appsec-php/actions/workflows/extension.yml/badge.svg?branch=master)
![helper](https://github.com/DataDog/dd-appsec-php/actions/workflows/helper.yml/badge.svg?branch=master)
![integration](https://github.com/DataDog/dd-appsec-php/actions/workflows/integration.yml/badge.svg?branch=master)
[![Coverage status](https://codecov.io/github/DataDog/dd-appsec-php/coverage.svg?branch=master)](https://codecov.io/github/DataDog/dd-appsec-php?branch=master)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.0-8892BF.svg)](https://php.net/)
[![Minimum Tracer Version](https://img.shields.io/badge/tracer-%3E%3D%200.67.0-2892BF.svg)](https://github.com/DataDog/dd-trace-php)
[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](LICENSE)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

Datadog Application Security (AppSec) extension for PHP. 

## Installation

To install the Datadog AppSec extension, first download the [installer](https://raw.githubusercontent.com/DataDog/dd-trace-php/cataphract/appsec-installer/dd-library-php-setup.php) from the Datadog Tracer repository. 

```
$ wget https://raw.githubusercontent.com/DataDog/dd-trace-php/cataphract/appsec-installer/dd-library-php-setup.php
```
:exclamation: This extension requires version `0.67.0` or above of the [Datadog PHP Tracer (ddtrace)](https://github.com/DataDog/dd-trace-php) and version `7.0` or above of [PHP](https://php.net). 

### Online Installation 

This installation requires an active internet connection and the installer mentioned above, it will automatically find and download the required versions from Github and install them in your system. 

To install both the Tracer and AppSec extensions, use the following command:
```
$ php dd-library-php-setup.php --tracer-version=latest --appsec-version=latest
```

### Offline Installation

The offline installation provides a way to install an existing AppSec or Tracer archive.

To install both the Tracer and AppSec extension, use the following command, please note that the archive names might differ:
```
$ php dd-library-php-setup.php --tracer-file datadog-php-tracer-0.67.0.x86_64.tar.gz --appsec-file dd-appsec-php-0.1.0-amd64.tar.gz
```

### Verifying the installation

Once the installation has concluded, the best way to verify that the extensions have been correctly installed is by checking `phpinfo()` on your application(s) and looking at the relevant sections for each of the extensions. 

### Configuration

After the installation has been completed, the AppSec extension will be loaded but disabled by default. Enabling the extension can be done through the `ini` settings or through environment variables.

To enable the extension using `ini` settings, find the extension's `ini` file, which can usually be found in  `/etc/php/<version>/xxx/conf.d/98-ddappsec.ini` but may differ depending on your installation. Consult the top of the output of `phpinfo()` to identify the directory that is scanned for `.ini` files, if any. Once the settings file has been found, locate, uncomment and set the following variable:
```
datadog.appsec.enabled = true
```
To enable the extension using the environment, make sure `DD_APPSEC_ENABLED=true` is exported on the environment of the PHP runtime. Exporting this environment variable to the runtime will depend on your installation.

## Development

### Building the extension & helper

This project requires cmake to build both the extension and helper, so building the main components is as simple as running the following commands in the root of the repository:
```
mkdir build
cd build
cmake ..
make -j
```
This will produce the extension, `ddappsec.so` and the helper process `ddappsec-helper`.

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
