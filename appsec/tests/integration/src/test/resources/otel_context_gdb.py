# /// script
# requires-python = ">=3.8"
# ///

import gdb
import struct
import sys


WAIT_FLAG = "ddappsec_debugger_wait_continue"
WAIT_FRAME_MARKER = "datadog_appsec_testing_wait_for_debugger"
TLS_SYMBOL = "otel_thread_ctx_v1"
THREAD_CONTEXT_SIZE = 640
EXPECTED_PROCESS_CONTEXT_MAPPING = "OTEL_CTX"
EXPECTED_PROCESS_CONTEXT_SIGNATURE = b"OTEL_CTX"


def print_kv(key, value):
    print(f"{key}={value}")


def bool_value(value):
    return "true" if value else "false"


def inferior():
    return gdb.selected_inferior()


def read_memory(address, size):
    return bytes(inferior().read_memory(address, size))


def read_pointer(address):
    pointer_size = gdb.lookup_type("void").pointer().sizeof
    return int.from_bytes(read_memory(address, pointer_size), sys.byteorder)


def call_pointer(expression):
    try:
        return int(gdb.parse_and_eval(expression))
    except gdb.error:
        return 0


def c_string(value):
    return value.replace("\\", "\\\\").replace('"', '\\"')


def frame_names(thread):
    names = []
    thread.switch()

    try:
        frame = gdb.newest_frame()
    except gdb.error:
        return names

    while frame:
        try:
            name = frame.name()
        except gdb.error:
            name = None

        if name:
            names.append(name)

        try:
            frame = frame.older()
        except gdb.error:
            break

    return names


def select_wait_for_debugger_thread(emit=True):
    threads = inferior().threads()
    if len(threads) == 1:
        threads[0].switch()
        if emit:
            print_kv("thread", threads[0].num)
        return

    inspected = []
    for thread in threads:
        names = frame_names(thread)
        inspected.append(f"{thread.num}:{'|'.join(names[:8])}")
        if any(WAIT_FRAME_MARKER in name for name in names):
            thread.switch()
            if emit:
                print_kv("thread", thread.num)
            return

    raise gdb.GdbError(
        "Could not find thread stopped in wait_for_debugger; inspected "
        + "; ".join(inspected)
    )


def find_tls_slot():
    slot = call_pointer(f"(void *) &{TLS_SYMBOL}")
    if slot:
        return slot

    slot = call_pointer(f'(void *) dlsym((void *) 0, "{TLS_SYMBOL}")')
    if slot:
        return slot

    for objfile in gdb.objfiles():
        if not objfile.filename or not objfile.filename.endswith("ddtrace.so"):
            continue

        handle = call_pointer(f'(void *) dlopen("{c_string(objfile.filename)}", 6)')
        if handle:
            slot = call_pointer(f'(void *) dlsym((void *) {handle}, "{TLS_SYMBOL}")')
            if slot:
                return slot

    return 0


class OtelThreadContext(gdb.Command):
    def __init__(self):
        super().__init__("otel-thread-context", gdb.COMMAND_DATA)

    def invoke(self, arg, from_tty):
        del arg, from_tty

        select_wait_for_debugger_thread()
        slot = find_tls_slot()
        print_kv("slot", f"0x{slot:x}" if slot else "0x0")
        if slot == 0:
            return

        ctx = read_pointer(slot)
        print_kv("ctx", f"0x{ctx:x}" if ctx else "0x0")
        if ctx == 0:
            return

        data = read_memory(ctx, THREAD_CONTEXT_SIZE)
        attrs_data_size = struct.unpack_from("<H", data, 26)[0]

        print_kv("trace_id", data[0:16].hex())
        print_kv("span_id", data[16:24].hex())
        print_kv("valid", data[24])
        print_kv("attrs_data_size", attrs_data_size)
        print_kv("attr0_key", data[28])
        print_kv("attr0_len", data[29])
        print_kv("local_root_span_id", data[30:46].decode("ascii"))


class OtelProcessContext(gdb.Command):
    def __init__(self):
        super().__init__("otel-process-context", gdb.COMMAND_DATA)

    def invoke(self, arg, from_tty):
        del arg, from_tty

        mapping = self.find_mapping()
        if mapping is None:
            print_kv("present", "false")
            return

        header = read_memory(mapping, 32)
        signature, version, payload_size, published_at, payload_ptr = struct.unpack(
            "<8sIIQQ", header
        )
        payload = read_memory(payload_ptr, payload_size)

        print_kv("present", "true")
        print_kv("signature", signature.rstrip(b"\0").decode("ascii"))
        print_kv("version", version)
        print_kv("payload_size", payload_size)
        print_kv("published_at", published_at)
        print_kv(
            "has_threadlocal_schema_key",
            bool_value(b"threadlocal.schema_version" in payload),
        )
        print_kv(
            "has_threadlocal_schema_value",
            bool_value(b"tlsdesc_v1_dev" in payload),
        )
        print_kv(
            "has_threadlocal_attribute_key_map",
            bool_value(b"threadlocal.attribute_key_map" in payload),
        )
        print_kv(
            "has_local_root_span_key",
            bool_value(b"datadog.local_root_span_id" in payload),
        )

    @staticmethod
    def find_mapping():
        with open(f"/proc/{inferior().pid}/maps", "r", encoding="utf-8") as maps:
            for line in maps:
                if EXPECTED_PROCESS_CONTEXT_MAPPING not in line:
                    continue
                start, _ = line.split(None, 1)[0].split("-", 1)
                return int(start, 16)

        with open(f"/proc/{inferior().pid}/maps", "r", encoding="utf-8") as maps:
            for line in maps:
                fields = line.split(None, 5)
                if len(fields) < 2 or "r" not in fields[1]:
                    continue

                start, end = fields[0].split("-", 1)
                start = int(start, 16)
                end = int(end, 16)
                if end - start < 32:
                    continue

                try:
                    if read_memory(start, 8) == EXPECTED_PROCESS_CONTEXT_SIGNATURE:
                        return start
                except gdb.MemoryError:
                    continue
        return None


class DdappsecContinue(gdb.Command):
    def __init__(self):
        super().__init__("ddappsec-continue", gdb.COMMAND_RUNNING)

    def invoke(self, arg, from_tty):
        del arg, from_tty
        select_wait_for_debugger_thread(emit=False)
        gdb.execute(f"set var {WAIT_FLAG} = 1")


OtelThreadContext()
OtelProcessContext()
DdappsecContinue()
