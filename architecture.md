# Architecture

The structure of the project has changed quite a bit over time due to changes
in project scope, PHP versions supported, etc. This is a living document to
describe the project's current architecture and the direction it's headed.

 1. [Components](#components)
 2. [PHP version specific code](#php-version-specific-code)
 3. [Background sender](#background-sender)
 4. [Sidecar lifetime](#sidecar-lifetime)

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

## Sidecar lifetime

The sidecar can run in a separate process or as a thread inside the PHP host
process (`DD_TRACE_SIDECAR_CONNECTION_MODE=thread`). In PHP-FPM thread mode,
the master hosts the listener and workers connect to it over IPC. We need
this model because the extension does not control how its host reaps child
processes or how its service manager shuts the host down.

PHP-FPM uses `waitpid(-1, ...)` to reap children. PHP applications may also
use [`pcntl_waitpid(-1, ...)`][pcntl-waitpid]. These calls wait for any child,
so they can consume the sidecar's exit status instead of an application
worker's. Keeping the sidecar as a child would make its lifecycle management
compete with the host's child handling.

Daemonizing normally avoids this by detaching the sidecar from its PHP
parent. However, that creates problems in some environments:

- If PID 1 adopts the sidecar but does not reap exited children, the sidecar
  leaves a zombie when it exits.
- A [systemd unit][systemd] can send the graceful shutdown signal only to
  its main process. With `KillMode=mixed`, for example, the remaining
  processes can receive `SIGKILL` as soon as the main process exits. A
  daemonized sidecar gets no initial shutdown signal and may be killed
  before it finishes flushing buffered data.
- [Docker sends the stop signal to the container's main process][docker-stop],
  PID 1. Without that process forwarding the signal and coordinating
  shutdown, a separate sidecar can lose buffered data when the container
  stops.

Once detached, the sidecar is normally no longer a child that PHP can
simply await with `waitpid`. Waiting for it to flush and terminate would
require additional coordination before the host exits and its unit or
container is torn down. Thread mode keeps the sidecar within the host's
lifetime: shutdown code can coordinate flushing and wait for the listener
thread before allowing the process to exit, without depending on PID 1 to
reap a daemon or forward its shutdown signal.

[pcntl-waitpid]: https://www.php.net/manual/en/function.pcntl-waitpid.php
[systemd]: https://github.com/systemd/systemd/blob/main/man/systemd.kill.xml
[docker-stop]: https://docs.docker.com/reference/cli/docker/container/stop/
