#!/usr/bin/env python3
"""Validate that a pprof contains a real PHP stack produced by the SSI workload."""

import ctypes
import ctypes.util
import sys


class ProfileError(Exception):
    pass


class ZstdInputBuffer(ctypes.Structure):
    _fields_ = [
        ("src", ctypes.c_void_p),
        ("size", ctypes.c_size_t),
        ("pos", ctypes.c_size_t),
    ]


class ZstdOutputBuffer(ctypes.Structure):
    _fields_ = [
        ("dst", ctypes.c_void_p),
        ("size", ctypes.c_size_t),
        ("pos", ctypes.c_size_t),
    ]


def decompress_zstd(filename):
    library_name = ctypes.util.find_library("zstd") or "libzstd.so.1"
    try:
        zstd = ctypes.CDLL(library_name)
    except OSError as error:
        raise ProfileError("could not load libzstd from the test image: {}".format(error))

    zstd.ZSTD_createDStream.restype = ctypes.c_void_p
    zstd.ZSTD_freeDStream.argtypes = [ctypes.c_void_p]
    zstd.ZSTD_initDStream.argtypes = [ctypes.c_void_p]
    zstd.ZSTD_initDStream.restype = ctypes.c_size_t
    zstd.ZSTD_DStreamOutSize.restype = ctypes.c_size_t
    zstd.ZSTD_decompressStream.argtypes = [
        ctypes.c_void_p,
        ctypes.POINTER(ZstdOutputBuffer),
        ctypes.POINTER(ZstdInputBuffer),
    ]
    zstd.ZSTD_decompressStream.restype = ctypes.c_size_t
    zstd.ZSTD_isError.argtypes = [ctypes.c_size_t]
    zstd.ZSTD_isError.restype = ctypes.c_uint
    zstd.ZSTD_getErrorName.argtypes = [ctypes.c_size_t]
    zstd.ZSTD_getErrorName.restype = ctypes.c_char_p

    with open(filename, "rb") as profile_file:
        compressed = profile_file.read()
    if not compressed:
        raise ProfileError("compressed profile is empty")

    stream = zstd.ZSTD_createDStream()
    if not stream:
        raise ProfileError("libzstd could not allocate a decompression stream")

    def check(code, operation):
        if zstd.ZSTD_isError(code):
            name = zstd.ZSTD_getErrorName(code).decode("utf-8", "replace")
            raise ProfileError("{}: {}".format(operation, name))
        return code

    source = ctypes.create_string_buffer(compressed)
    input_buffer = ZstdInputBuffer(ctypes.cast(source, ctypes.c_void_p), len(compressed), 0)
    output_size = zstd.ZSTD_DStreamOutSize()
    chunks = []
    remaining = 1
    try:
        check(zstd.ZSTD_initDStream(stream), "initialize zstd decoder")
        while input_buffer.pos < input_buffer.size:
            before = input_buffer.pos
            output = ctypes.create_string_buffer(output_size)
            output_buffer = ZstdOutputBuffer(ctypes.cast(output, ctypes.c_void_p), output_size, 0)
            remaining = check(
                zstd.ZSTD_decompressStream(
                    stream, ctypes.byref(output_buffer), ctypes.byref(input_buffer)),
                "decompress zstd profile",
            )
            chunks.append(output.raw[:output_buffer.pos])
            if input_buffer.pos == before and output_buffer.pos == 0:
                raise ProfileError("zstd decoder made no progress")
    finally:
        zstd.ZSTD_freeDStream(stream)

    if remaining != 0:
        raise ProfileError("zstd profile is truncated ({} bytes still expected)".format(remaining))
    return b"".join(chunks)


def read_varint(data, offset, context):
    value = 0
    shift = 0
    start = offset
    while offset < len(data) and shift < 70:
        byte = data[offset]
        offset += 1
        value |= (byte & 0x7F) << shift
        if byte < 0x80:
            return value, offset
        shift += 7
    raise ProfileError("{}: invalid varint at byte {}".format(context, start))


def fields(data, context):
    offset = 0
    while offset < len(data):
        tag, offset = read_varint(data, offset, context)
        number = tag >> 3
        wire_type = tag & 7
        if number == 0:
            raise ProfileError("{}: field number zero at byte {}".format(context, offset))
        if wire_type == 0:
            value, offset = read_varint(data, offset, context)
        elif wire_type == 1:
            if offset + 8 > len(data):
                raise ProfileError("{}: truncated fixed64 field {}".format(context, number))
            value = data[offset:offset + 8]
            offset += 8
        elif wire_type == 2:
            length, offset = read_varint(data, offset, context)
            end = offset + length
            if end > len(data):
                raise ProfileError("{}: truncated field {}".format(context, number))
            value = data[offset:end]
            offset = end
        elif wire_type == 5:
            if offset + 4 > len(data):
                raise ProfileError("{}: truncated fixed32 field {}".format(context, number))
            value = data[offset:offset + 4]
            offset += 4
        else:
            raise ProfileError("{}: unsupported protobuf wire type {}".format(context, wire_type))
        yield number, wire_type, value


def packed_varints(data, context):
    values = []
    offset = 0
    while offset < len(data):
        value, offset = read_varint(data, offset, context)
        values.append(value)
    return values


def integer_values(message, field_number, context):
    values = []
    for number, wire_type, value in fields(message, context):
        if number != field_number:
            continue
        if wire_type == 0:
            values.append(value)
        elif wire_type == 2:
            values.extend(packed_varints(value, context + " packed field"))
        else:
            raise ProfileError("{}: field {} has unexpected wire type {}".format(
                context, field_number, wire_type))
    return values


def one_integer(message, field_number, context):
    values = integer_values(message, field_number, context)
    if len(values) != 1:
        raise ProfileError("{}: expected one field {}, found {}".format(
            context, field_number, len(values)))
    return values[0]


def nested_messages(message, field_number, context):
    result = []
    for number, wire_type, value in fields(message, context):
        if number == field_number:
            if wire_type != 2:
                raise ProfileError("{}: field {} is not length-delimited".format(context, field_number))
            result.append(value)
    return result


def string_at(strings, index, context):
    if index >= len(strings):
        raise ProfileError("{}: string-table index {} is out of range (size {})".format(
            context, index, len(strings)))
    return strings[index]


def validate_profile(data):
    top = list(fields(data, "profile"))

    strings = []
    for number, wire_type, value in top:
        if number == 6:
            if wire_type != 2:
                raise ProfileError("profile: string-table entry is not length-delimited")
            try:
                strings.append(value.decode("utf-8"))
            except UnicodeDecodeError as error:
                raise ProfileError("profile: string-table entry is not UTF-8: {}".format(error))
    if not strings or strings[0] != "":
        raise ProfileError("profile: missing required empty first string-table entry")

    sample_types = []
    for index, message in enumerate(nested_messages(data, 1, "profile")):
        type_index = one_integer(message, 1, "sample_type[{}]".format(index))
        unit_index = one_integer(message, 2, "sample_type[{}]".format(index))
        sample_types.append((
            string_at(strings, type_index, "sample type"),
            string_at(strings, unit_index, "sample unit"),
        ))
    if not sample_types:
        raise ProfileError("profile: no sample types")

    functions = {}
    for index, message in enumerate(nested_messages(data, 5, "profile")):
        function_id = one_integer(message, 1, "function[{}]".format(index))
        name_index = one_integer(message, 2, "function[{}]".format(index))
        if function_id in functions:
            raise ProfileError("profile: duplicate function id {}".format(function_id))
        functions[function_id] = string_at(strings, name_index, "function {}".format(function_id))

    locations = {}
    for index, message in enumerate(nested_messages(data, 4, "profile")):
        location_id = one_integer(message, 1, "location[{}]".format(index))
        function_ids = []
        for line_index, line in enumerate(nested_messages(message, 4, "location[{}]".format(index))):
            function_ids.append(one_integer(
                line, 1, "location[{}].line[{}]".format(index, line_index)))
        if location_id in locations:
            raise ProfileError("profile: duplicate location id {}".format(location_id))
        locations[location_id] = function_ids

    expected = ["ssi_profile_leaf", "ssi_profile_middle", "ssi_profile_root"]
    observed_stacks = []
    expected_stack_samples = []
    matching_wall_stack = None
    matching_cpu_stack = None
    samples = nested_messages(data, 2, "profile")
    for index, message in enumerate(samples):
        location_ids = integer_values(message, 1, "sample[{}]".format(index))
        values = integer_values(message, 2, "sample[{}]".format(index))
        if len(values) != len(sample_types):
            raise ProfileError("sample[{}]: {} values for {} sample types".format(
                index, len(values), len(sample_types)))

        stack = []
        for location_id in location_ids:
            if location_id not in locations:
                raise ProfileError("sample[{}]: unknown location id {}".format(index, location_id))
            for function_id in locations[location_id]:
                if function_id not in functions:
                    raise ProfileError("sample[{}]: unknown function id {}".format(index, function_id))
                stack.append(functions[function_id])
        observed_stacks.append(stack)

        cursor = 0
        for name in stack:
            if name == expected[cursor]:
                cursor += 1
                if cursor == len(expected):
                    break
        stack_matches = cursor == len(expected)
        values_by_type = {
            sample_type: value
            for (sample_type, _unit), value in zip(sample_types, values)
        }
        if stack_matches:
            expected_stack_samples.append((stack, values_by_type))
            if values_by_type.get("wall-time", 0) > 0:
                matching_wall_stack = stack
            if values_by_type.get("cpu-time", 0) > 0:
                matching_cpu_stack = stack

    if not samples:
        raise ProfileError("profile: contains no samples")

    if not expected_stack_samples:
        relevant = []
        for stack in observed_stacks:
            if any(name.startswith("ssi_profile_") for name in stack):
                relevant.append(" <- ".join(stack))
            if len(relevant) == 5:
                break
        detail = "none contained an ssi_profile_* frame" if not relevant else "; ".join(relevant)
        raise ProfileError(
            "no sample contained leaf <- middle <- root; relevant observed stacks: {}".format(detail))

    observed_values = "; ".join(
        "wall-time={}, cpu-time={}".format(
            values_by_type.get("wall-time", "missing"),
            values_by_type.get("cpu-time", "missing"),
        )
        for _stack, values_by_type in expected_stack_samples[:5]
    )
    if matching_wall_stack is None:
        raise ProfileError(
            "no sample with leaf <- middle <- root had positive wall-time; observed values: {}".format(
                observed_values))
    if sys.platform.startswith("linux") and matching_cpu_stack is None:
        raise ProfileError(
            "no sample with leaf <- middle <- root had positive CPU-time on Linux; observed values: {}".format(
                observed_values))

    print("Profile validation passed")
    print("  sample types: {}".format(
        ", ".join("{}/{}".format(name, unit) for name, unit in sample_types)))
    print("  samples: {}".format(len(samples)))
    print("  wall-time stack: {}".format(" <- ".join(matching_wall_stack)))
    if sys.platform.startswith("linux"):
        print("  CPU-time stack: {}".format(" <- ".join(matching_cpu_stack)))


def main():
    if len(sys.argv) != 2:
        print("Usage: verify_pprof.py <compressed-pprof.zst>", file=sys.stderr)
        return 2
    try:
        validate_profile(decompress_zstd(sys.argv[1]))
    except (OSError, ProfileError) as error:
        print("FAIL [profile validation]: {}".format(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
