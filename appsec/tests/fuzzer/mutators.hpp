// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <cstdint>
#include <cstdlib>

// Forward-declare the libFuzzer's mutator callback.
extern "C" size_t
LLVMFuzzerMutate(uint8_t *Data, size_t Size, size_t MaxSize);

size_t NopMutator(uint8_t *Data, size_t Size,
                  size_t MaxSize, unsigned int Seed);

size_t RawMutator(uint8_t *Data, size_t Size,
                  size_t MaxSize, unsigned int Seed);

size_t MessageBodyMutator(uint8_t *Data, size_t Size,
                          size_t MaxSize, unsigned int Seed);
