// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include <msgpack.hpp>
#include <network/proto.hpp>
#include "helpers.hpp"
#include "mutators.hpp"
#include <iostream>

size_t NopMutator([[maybe_unused]]uint8_t *Data, size_t Size,
         [[maybe_unused]] size_t MaxSize, [[maybe_unused]] unsigned int Seed)
{
    return Size;
}

size_t RawMutator(uint8_t *Data, size_t Size,
                  size_t MaxSize, [[maybe_unused]] unsigned int Seed)
{
    return LLVMFuzzerMutate(Data, Size, MaxSize);
}

size_t MessageBodyMutator(uint8_t *Data, size_t Size,
                          size_t MaxSize, [[maybe_unused]] unsigned int Seed)
{
    try {
        if (Size == 0) { throw; }

        dds::fuzzer::reader reader(Data, Size);
        dds::fuzzer::writer writer(MaxSize);

        while (writer.remaining_bytes() >= sizeof(dds::network::header_t)) {
            // Find the header
            dds::network::header_t old_header;
            if (!reader.read<dds::network::header_t>(old_header) || old_header.size == 0) {
                // No more messages available
                break;
            }

            // Write the header
            dds::network::header_t *new_header{nullptr};
            {
                auto [ptr, len] = writer.write(&old_header);
                new_header = reinterpret_cast<dds::network::header_t*>(ptr);
            }

            if (writer.remaining_bytes() < old_header.size) { 
                break;
            }

            {
                auto [ptr, len] = writer.write(reader.get_cursor(), old_header.size);
                new_header->size = LLVMFuzzerMutate(ptr, len, writer.remaining_bytes());
            }

            reader.read_bytes(nullptr, old_header.size);
        }

        Size = writer.written_bytes();
        memcpy(Data, writer.get_start(), Size);
    } catch(...) {
        // Something weird happened, bail
        abort();
    }

    return Size;
}
