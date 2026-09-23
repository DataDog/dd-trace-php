"""Executable for PHP's PHPT harness, using only the declared OCI PHP closure."""

import json
import os
import sys


def main():
    command = json.loads(os.environ["PHPT_PHP_COMMAND"]) + sys.argv[1:]
    os.execve(command[0], command, os.environ)


if __name__ == "__main__":
    main()
