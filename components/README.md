# Components

Components should be the main building blocks of PHP-agnostic code. They have a
single header and source file that matches the component name. They have a tests
folder for doing unit tests which integrate with CMake. Here is the layout for
an example component, the `string_view`:

    components/string_view/
    ├── CMakeLists.txt
    ├── string_view.c
    ├── string_view.h
    └── tests
        ├── CMakeLists.txt
        └── string_view.cc

Components may depend on other components or on libraries, but must not depend
on anything from the php-src codebase.

## Components must not depend on PHP

Components must not use any headers or other things from the PHP code base.
Components can be used across all PHP versions because they are divorced from
the PHP implementation details.

Additionally, it makes them much easier to test when they are not coupled to PHP
at all. Testing internal units was a notable weakness of the codebase before
moving to components, and sometimes internal features would expose PHP functions
that existed only to test internal features. This is an anti-pattern that is
avoided by using components.

## Components should be tested

Components must have a tests subdirectory which gets added to the CMake project
when the `DATADOG_PHP_TESTING` option is set to true/on. The tests need to
integrate with CMake so that when the main `test` target is run that the
component's tests are run as well.

Currently, all existing tests use the C++ Catch2 testing framework. This is not
a requirement, but it helps to be consistent.

The tests directory can contain more than one test file if desired, but often
there will be just a single test file.

## Components may use libraries

Components can wrap third-party libraries from outside of this project, such as
wrapping a hashing library, or a logging library. When doing this, try not to
let the underlying library leak through the component; otherwise we would just
use the library directly without reaching for a component.

There should still be tests for components based on libraries.

## Building and using components

Components must have a CMakeLists.txt for building with CMake. As mentioned
previously, this is used to test components. I expect we will integrate the
artifacts from CMake builds into the main extension, but we have not done this
yet. This means you still need to add the source file to
`DD_TRACE_COMPONENT_SOURCES` in [config.m4](../config.m4). You do not need to
add anything to the include path, as `components/` is already a part of the
include directories.

Component headers should be included by using double-quotes, and referring to
the component name and then the component header. For example:

```c
#include "string_view/string_view.h"
```

All symbols that are in the header should have a prefix of `datadog_php_`.
Macros should have a prefix of `DATADOG_PHP_`.

## Components do not use globals

Components must avoid creating and using global state. If you think you need
global state, such as thread-local globals for a PHP extension, then what you
need to do is make a component that does not use global state which is then
wrapped by something that is specifically designed for handling the global
state. The details haven't been fleshed out yet, but these will probably be
called "plugins", so you would have a `component_plugin` that uses a
`component` internally (or even publicly, we don't need to hide that).

