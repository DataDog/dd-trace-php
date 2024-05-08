# Libdatadog
Common components are integrated in dd-trace-php by importing the source code from the libdatadog repository. As stated in
the [contributing file](https://github.com/DataDog/dd-trace-php/CONTRIBUTING.md) the code is imported by using git
submodules which downloads the sources into libdatadog folder located at the project’s root directory.
## Integration
The rust sources are compiled to assemble a static library named libddtrace_php.a, this library contains the necessary
functionality to:
* Sidecar initialization and communication.
* Sending telemetry.
* Common functionality like container identification and runtime id generation.

The compilation process is handled by a [compile script](https://github.com/DataDog/dd-trace-php/blob/master/compile_rust.sh)
which is instantiated from the main Makefile.
Once the library is assembled its contents are linked in ddtrace.so which is the main objective in the Makefile. This
library is the extension which the PHP engine will load at runtime in order to provide all tracing functionality.

## Adding new features
Upon adding new modules in the libdatadog repository there is the need to create the neccessary glue code in the php
side to use those new modules. For that purpose you'll need to follow the next steps:
* Modify the [project file in components-rs](https://github.com/DataDog/dd-trace-php/blob/master/components-rs/Cargo.toml) to add
the new dependency.
* Add the necessary code in components-rs to expose the functionality.
* Modify the compile script if any new configuration needs to be added to the compilation process.
