# Architecture

The structure of the project has changed quite a bit over time due to changes
in project scope, PHP versions supported, etc. This is a living document to
describe the project's current architecture and the direction it's headed.

 1. [Components](#components)
 2. [PHP version specific code](#php-version-specific-code)
 3. [Background sender](#background-sender)

## Components

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

Components are detailed in their own
[components/README.md](./components/README.md).

## PHP version specific code

We used to maintain code that was intended to work across all supported PHP
versions in a single file per "unit". This became problematic for a few reasons:

 1. It was hard to make an improvement or change for a given unit in one version
    without accidentally breaking another version.
 2. Not all features worked the same way at a technical level across the
    different PHP versions, and maintaining this in a single file was a pain.
 3. The `TSRMLS_CC` stuff was always annoying, and with PHP 8.0 these macros
    were removed altogether.

So, we split them into their own directories:

    ext
    ├── php5
    ├── php7
    └── php8

At the time of this writing, quite a bit of code is still duplicated across
these folders. The intent is for the pieces that are PHP version agnostic to
eventually become components.

## Background sender

The background sender is used to upload traces to the agent in a way that
doesn't block the PHP threads from continuing. This code is mostly PHP version
agnostic and should be split into a component.

The background sender's sources are in `ext/php$n/`, mostly in `comms_php.{c,h}`
and `coms.{c,h}`. The background sender's design altered part-way through, and
was not properly refactored. Roughly, it works like this:

  - A trace is encoded into msgpack, and then copied into a buffer that is owned
    by the background sender.
  - Only a single trace may be encoded at a time, but you can work around this
    by encoding each trace individually. If you send multiple traces in the same
    encoding, the background sender will reject it and the trace will fall back
    to an uploader written in PHP.
  - Each buffer may contain multiple traces, so it has accounting for this.
  - Originally a chunk of the trace could be uploaded, instead of the whole
    thing. This was later removed, but the design of working with spans instead
    of traces remains.
  - The background sender uploads the trace via libcurl to the agent every N
    requests or X milliseconds. These are both controlled via configuration.

This design is close to having a fixed-size, thread-safe queue of
msgpack-encoded traces. The next time this code is touched, it probably ought to
be cleaned up to more closely match that design, or better yet use a shared
library for doing it (does not exist at this time, but has been discussed).

### Background sender configuration

Functions like `getenv` are not thread-safe, and the background sender uses a
thread. To work around this, the configuration is memoized. The directory
`ext/php$n/` has files `configuration.{c,h}`, `configuration_php_iface.{c,h}`,
and `configuration_render.h`. If you are not familiar with the term "x macros",
you need to get acquainted with them before you can understand how it works.
