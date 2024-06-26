# DD Library Loader

## Getting Started

This Zend extension (**dd_library_loader**) is a universal loader for the **ddtrace** extension.

Once compiled (PHP >= 7.4), the same shared object can be loaded on any PHP versions from 7.0 to 8.3,
whatever the PHP flavor (NTS/ZTS, debug or not).

When loaded, it will try to find the right **ddtrace.so** file on the filesystem and load it into PHP.
