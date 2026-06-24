import gdb
import struct
import sys


WAIT_FLAG = "ddappsec_debugger_wait_continue"
WAIT_FRAME_MARKER = "datadog_appsec_testing_wait_for_debugger"
TLS_SYMBOL = "otel_thread_ctx_v1"
THREAD_CONTEXT_SIZE = 640
EXPECTED_PROCESS_CONTEXT_MAPPING = "OTEL_CTX"
EXPECTED_PROCESS_CONTEXT_SIGNATURE = b"OTEL_CTX"


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
        attrs = decode_thread_context_attrs(
            data, attrs_data_size, read_threadlocal_attribute_key_map()
        )

        print_kv("trace_id", data[0:16].hex())
        print_kv("span_id", data[16:24].hex())
        print_kv("valid", data[24])
        print_kv("attrs_data_size", attrs_data_size)
        for key, value in attrs.items():
            print_kv(key, value)


class OtelProcessContext(gdb.Command):
    def __init__(self):
        super().__init__("otel-process-context", gdb.COMMAND_DATA)

    def invoke(self, arg, from_tty):
        del arg, from_tty

        mapping = find_process_context_mapping()
        if mapping is None:
            print_kv("present", "false")
            return

        process_context = read_process_context_attributes(mapping)
        attributes = process_context["attributes"]

        print_kv("present", "true")
        print_kv("signature", process_context["signature"])
        print_kv("version", process_context["version"])
        print_kv("payload_size", process_context["payload_size"])
        print_kv("published_at", process_context["published_at"])
        for key, value in attributes.items():
            print_attribute(key, value)


class DdappsecContinue(gdb.Command):
    def __init__(self):
        super().__init__("ddappsec-continue", gdb.COMMAND_RUNNING)

    def invoke(self, arg, from_tty):
        del arg, from_tty
        select_wait_for_debugger_thread(emit=False)
        gdb.execute(f"set var {WAIT_FLAG} = 1")


def print_kv(key, value):
    print(f"{key}={value}")


def print_attribute(key, value):
    if isinstance(value, list):
        print_kv(key, ",".join(value))
    elif value is None:
        print_kv(key, "")
    else:
        print_kv(key, value)


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


def read_varint(data, offset):
    value = 0
    shift = 0

    while offset < len(data):
        byte = data[offset]
        offset += 1
        value |= (byte & 0x7F) << shift
        if byte < 0x80:
            return value, offset
        shift += 7

    raise ValueError("truncated protobuf varint")


def protobuf_fields(data):
    offset = 0

    while offset < len(data):
        key, offset = read_varint(data, offset)
        field_number = key >> 3
        wire_type = key & 0x07

        if wire_type == 0:
            value, offset = read_varint(data, offset)
        elif wire_type == 1:
            value = data[offset : offset + 8]
            offset += 8
        elif wire_type == 2:
            size, offset = read_varint(data, offset)
            value = data[offset : offset + size]
            offset += size
        elif wire_type == 5:
            value = data[offset : offset + 4]
            offset += 4
        else:
            raise ValueError(f"unsupported protobuf wire type {wire_type}")

        yield field_number, wire_type, value


def decode_any_value(data):
    for field_number, wire_type, value in protobuf_fields(data):
        if field_number == 1 and wire_type == 2:
            return value.decode("utf-8")
        if field_number == 5 and wire_type == 2:
            return decode_array_value(value)

    return None


def decode_array_value(data):
    values = []

    for field_number, wire_type, value in protobuf_fields(data):
        if field_number == 1 and wire_type == 2:
            values.append(decode_any_value(value))

    return values


def decode_key_value(data):
    key = None
    value = None

    for field_number, wire_type, field_value in protobuf_fields(data):
        if field_number == 1 and wire_type == 2:
            key = field_value.decode("utf-8")
        elif field_number == 2 and wire_type == 2:
            value = decode_any_value(field_value)

    return key, value


def decode_process_context_resource_attributes(data):
    attributes = {}

    for field_number, wire_type, value in protobuf_fields(data):
        if field_number != 1 or wire_type != 2:
            continue

        for resource_field_number, resource_wire_type, resource_value in protobuf_fields(
            value
        ):
            if resource_field_number == 1 and resource_wire_type == 2:
                key, attr_value = decode_key_value(resource_value)
                if key is not None:
                    attributes[key] = attr_value

    return attributes


def find_process_context_mapping():
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


def read_process_context_attributes(mapping):
    header = read_memory(mapping, 32)
    signature, version, payload_size, published_at, payload_ptr = struct.unpack(
        "<8sIIQQ", header
    )
    payload = read_memory(payload_ptr, payload_size)

    return {
        "signature": signature.rstrip(b"\0").decode("ascii"),
        "version": version,
        "payload_size": payload_size,
        "published_at": published_at,
        "attributes": decode_process_context_resource_attributes(payload),
    }


def read_threadlocal_attribute_key_map():
    mapping = find_process_context_mapping()
    if mapping is None:
        return []

    attribute_key_map = read_process_context_attributes(mapping)["attributes"].get(
        "threadlocal.attribute_key_map"
    )
    if isinstance(attribute_key_map, list):
        return attribute_key_map
    return []


def decode_thread_context_attrs(data, attrs_data_size, attribute_key_map):
    attrs = {}
    offset = 28
    end = offset + attrs_data_size

    while offset + 2 <= end:
        key_index = data[offset]
        value_length = data[offset + 1]
        value_start = offset + 2
        value_end = value_start + value_length
        if value_end > end:
            break

        if key_index < len(attribute_key_map):
            attrs[attribute_key_map[key_index]] = data[value_start:value_end].decode("utf-8")

        offset = value_end

    return attrs


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


OtelThreadContext()
OtelProcessContext()
DdappsecContinue()
