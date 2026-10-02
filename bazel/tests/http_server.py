"""Start the declared PHP CLI's built-in HTTP server for an itest."""

import os
import sys

from bazel.tests.php_test_runtime import clean_environment, php_command, runfile


def main():
    *runtime, endpoint, port = sys.argv[1:]
    environment = clean_environment()
    environment["DD_TRACE_ENABLED"] = "false"
    command = php_command(runtime) + ["-S", "127.0.0.1:" + port, str(runfile(endpoint))]
    os.execve(command[0], command, environment)


if __name__ == "__main__":
    main()
