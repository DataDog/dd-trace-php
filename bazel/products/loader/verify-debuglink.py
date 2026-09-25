#!/usr/bin/env python3
import pathlib
import struct
import subprocess
import sys
import tempfile
import zlib

objcopy, binary_name, debug_name = sys.argv[1:]
binary = pathlib.Path(binary_name)
debug = pathlib.Path(debug_name)
with tempfile.TemporaryDirectory() as scratch:
    section = pathlib.Path(scratch) / "debuglink"
    copied_binary = pathlib.Path(scratch) / binary.name
    subprocess.run(
        [
            objcopy,
            "--dump-section",
            ".gnu_debuglink=" + str(section),
            str(binary),
            str(copied_binary),
        ],
        check=True,
    )
    contents = section.read_bytes()

nul = contents.find(b"\0")
if nul < 0:
    raise SystemExit(".gnu_debuglink has no terminated filename")
filename = contents[:nul].decode("utf-8")
if filename != debug.name:
    raise SystemExit("debuglink filename %r does not match %r" % (filename, debug.name))
crc_offset = (nul + 4) & ~3
if len(contents) != crc_offset + 4:
    raise SystemExit("malformed .gnu_debuglink section")
recorded_crc = struct.unpack_from("<I", contents, crc_offset)[0]
actual_crc = zlib.crc32(debug.read_bytes()) & 0xFFFFFFFF
if recorded_crc != actual_crc:
    raise SystemExit("debuglink CRC mismatch: %08x != %08x" % (recorded_crc, actual_crc))
