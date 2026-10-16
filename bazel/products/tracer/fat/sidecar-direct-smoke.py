#!/usr/bin/env python3
"""Run the fat tracer's real sidecar entry with a passed seqpacket listener."""

import os
import pathlib
import socket
import subprocess
import sys
import tempfile


loader, library_path, extension, ping_client = sys.argv[1:]
with tempfile.TemporaryDirectory(prefix="ddtrace-sidecar-") as scratch:
    socket_path = pathlib.Path(scratch) / "listener.sock"
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_SEQPACKET)
    listener.bind(str(socket_path))
    listener.listen(4)

    env = dict(os.environ)
    env.update(
        {
            "__DD_INTERNAL_PASSED_FD": str(listener.fileno()),
            "_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS": "1",
            "_DD_SIDECAR_DIRECT_EXEC": "ddtrace_sidecar_entry_point",
            "_DD_SIDECAR_LOG_METHOD": "disabled",
        }
    )
    process = subprocess.Popen(
        [loader, "--inhibit-cache", "--library-path", library_path, extension],
        env=env,
        pass_fds=(listener.fileno(),),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    listener.close()
    try:
        ping = subprocess.run(
            [
                loader,
                "--inhibit-cache",
                "--library-path",
                library_path,
                ping_client,
                str(socket_path),
            ],
            check=False,
            capture_output=True,
            text=True,
            timeout=10,
        )
        if ping.returncode != 0 or ping.stdout != "sidecar ping passed\n":
            raise RuntimeError(
                f"sidecar ping failed: {ping.returncode}\n"
                f"stdout:\n{ping.stdout}\nstderr:\n{ping.stderr}"
            )
        stdout, stderr = process.communicate(timeout=10)
    except BaseException:
        process.kill()
        process.wait()
        raise
    if process.returncode != 0:
        raise SystemExit(
            f"sidecar did not terminate cleanly: {process.returncode}\n"
            f"stdout:\n{stdout}\nstderr:\n{stderr}"
        )
