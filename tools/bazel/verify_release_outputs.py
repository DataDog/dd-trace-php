"""Check the shipping ABI of standalone Rust shared libraries without host tools."""

import mmap
from pathlib import Path
import struct

_ELF_HEADER = struct.Struct("<16sHHIQQQIHHHHHH")
_SECTION = struct.Struct("<IIQQQQIIQQ")
_SYMBOL = struct.Struct("<IBBHQQ")
_MACHINE = {"amd64": 62, "arm64": 183}
_REQUIRED_EXPORTS = {"ddog_sidecar_ping", "ddog_daemon_entry_point"}


def _cstring(data, offset):
    if offset >= len(data):
        raise ValueError("ELF string offset is out of bounds")
    end = data.find(b"\0", offset)
    if end < 0:
        raise ValueError("Unterminated ELF string")
    return data[offset:end].decode("ascii")


def verify_standalone(path, arch):
    """Require ELF64 DSO, target machine, debug sections and C ABI exports."""
    with Path(path).open("rb") as source, mmap.mmap(source.fileno(), 0, access=mmap.ACCESS_READ) as data:
        if len(data) < _ELF_HEADER.size:
            raise ValueError("Truncated standalone shared library: " + str(path))
        header = _ELF_HEADER.unpack_from(data)
        ident, elf_type, machine = header[:3]
        if ident[:6] != b"\x7fELF\x02\x01" or elf_type != 3 or machine != _MACHINE[arch]:
            raise ValueError("Wrong standalone shared-library ELF ABI: " + str(path))
        section_offset, section_size, section_count, names_index = header[6], header[11], header[12], header[13]
        if section_size != _SECTION.size or section_count == 0 or names_index >= section_count or \
                section_offset + section_size * section_count > len(data):
            raise ValueError("Invalid standalone shared-library section table: " + str(path))
        sections = [_SECTION.unpack_from(data, section_offset + i * section_size)
                    for i in range(section_count)]

        def content(section):
            offset, size = section[4], section[5]
            if offset + size > len(data):
                raise ValueError("ELF section exceeds file: " + str(path))
            return data[offset:offset + size]

        names = content(sections[names_index])
        section_names = {_cstring(names, section[0]) for section in sections}
        if not ({".debug_info", ".zdebug_info"} & section_names):
            raise ValueError("Standalone Rust DSO lacks debug information: " + str(path))
        exports = set()
        for section in sections:
            if section[1] != 11:  # SHT_DYNSYM
                continue
            if section[6] >= section_count or section[9] != _SYMBOL.size:
                raise ValueError("Invalid dynamic symbol table: " + str(path))
            symbols, strings = content(section), content(sections[section[6]])
            if len(symbols) % _SYMBOL.size:
                raise ValueError("Truncated dynamic symbol table: " + str(path))
            for offset in range(0, len(symbols), _SYMBOL.size):
                symbol = _SYMBOL.unpack_from(symbols, offset)
                if symbol[3] and symbol[1] >> 4 in (1, 2):
                    exports.add(_cstring(strings, symbol[0]))
        missing = _REQUIRED_EXPORTS - exports
        if missing:
            raise ValueError("Standalone Rust DSO missing C ABI exports %s: %s" %
                             (sorted(missing), path))
        return dict(path=str(path), machine=machine, exports=sorted(_REQUIRED_EXPORTS),
                    debug=True)
