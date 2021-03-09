#include "uuid.h"

/* The nibble_to_hex function is by Wojciech Muła. It is a SIMD friendly way to
 * hex encode half a byte. It has BSD 2-clause "Simplified" license:
Copyright (c) 2005-2016, Wojciech Muła
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are
met:

1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS
IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED
TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
static char nibble_to_hex(uint8_t byte) {
    byte &= 15u;

    const char corr = 'a' - '0' - 10;
    const char c = byte + '0';

    uint8_t tmp = 128 - 10 + byte;
    uint8_t msb = tmp & 0x80;

    uint8_t mask = msb - (msb >> 7);  // 0x7f or 0x00

    return c + (mask & corr);
}

void datadog_php_uuid_default_ctor(datadog_php_uuid *uuid) {
    for (unsigned i = 0; i != 16; ++i) {
        uuid->data[i] = 0;
    }
}

void datadog_php_uuidv4_bytes_ctor(datadog_php_uuid *uuid, const uint8_t src[]) {
    for (unsigned i = 0; i != 16; ++i) {
        uuid->data[i] = src[i];
    }

    /* See RFC 4122, section 4.4
     * Algorithms for Creating a UUID from Truly Random or Pseudo-Random Numbers
     */

    /* Set the four most significant bits (bits 12 through 15) of the
     * time_hi_and_version field to the 4-bit version number from
     * Section 4.1.3.
     * ----
     * We want this pattern, where X preserves what is there.
     * 0100XXXX
     */
    uuid->data[6] = 0x40 | (uuid->data[6] & 0xf);

    /* Set the two most significant bits (bits 6 and 7) of the
     * clock_seq_hi_and_reserved to zero and one, respectively.
     * -----
     * We want this pattern, where X preserves what is there.
     * 10XXXXXX
     */
    uuid->data[8] = 0x80 | (uuid->data[8] & 0x3f);
}

void datadog_php_uuid_encode32(datadog_php_uuid uuid, char *dest) {
    char *out = dest;
#if __clang__
#pragma clang loop vectorize(enable)
#endif
    for (unsigned i = 0; i < 16; ++i, out += 2) {
        uint8_t in = uuid.data[i];
        char first = nibble_to_hex(in >> UINT8_C(4));
        char second = nibble_to_hex(in & UINT8_C(15));
        *out = first;
        *(out + 1) = second;
    }
}

/**
 * Encodes the `uuid` into a 36 character ASCII string as defined by RFC 4122
 * (https://tools.ietf.org/html/rfc4122#section-4.1), and stores the result in
 * `dest`.
 *
 * @param dest A buffer at least 36 chars in length.
 * @param uuid The UUID to encode.
 */
void datadog_php_uuid_encode36(datadog_php_uuid uuid, char *dest) {
    /* This could be more efficient if we didn't call to encode32 and then
     * fix it up after, but this is not performance sensitive.
     */
    _Alignas(16) char src[32];
    datadog_php_uuid_encode32(uuid, src);

    unsigned dest_offset = 0, src_offset = 0;
    for (unsigned i = 0; i++ != 8; ++dest_offset, ++src_offset) {
        dest[dest_offset] = src[src_offset];
    }

    dest[dest_offset++] = '-';
    for (unsigned i = 0; i++ != 4; ++dest_offset, ++src_offset) {
        dest[dest_offset] = src[src_offset];
    }

    dest[dest_offset++] = '-';
    for (unsigned i = 0; i++ != 4; ++dest_offset, ++src_offset) {
        dest[dest_offset] = src[src_offset];
    }

    dest[dest_offset++] = '-';
    for (unsigned i = 0; i++ != 4; ++dest_offset, ++src_offset) {
        dest[dest_offset] = src[src_offset];
    }

    dest[dest_offset++] = '-';
    for (unsigned i = 0; i++ != 12; ++dest_offset, ++src_offset) {
        dest[dest_offset] = src[src_offset];
    }
}
