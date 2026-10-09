"""Explicitly selected PHPT cases supported by the hermetic runner."""

load("@rules_python//python:defs.bzl", "py_test")

PHPT_CASES = [
    "extension_disabled",
    "read_c_configuration",
    "dd_trace_multiple_write",
    "do_not_check_if_class_or_function_exists_by_default",
]

def php_phpt_tests(runtime, runtime_args, platform):
    for name in PHPT_CASES:
        phpt = "//tests/ext:%s.phpt" % name
        py_test(
            name = "phpt_" + name,
            srcs = ["phpt_test.py", "php_test_runtime.py"],
            main = "phpt_test.py",
            args = runtime_args + ["$(rootpath %s)" % phpt],
            data = runtime + [phpt],
            env = {"PYTHONDONTWRITEBYTECODE": "1"},
            target_compatible_with = platform,
        )
