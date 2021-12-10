#include "uuid.h"

/**
 * @param byte A number in the range 0 to 15, inclusive. If the value is out of
 *             this range, the result is undefined.
 * @return The equivalent hex char [0-9a-f].
 */
static char nibble_to_hex(uint8_t byte) {
    /* Simple algorithm based on ASCII tables:
     *   if byte > 9 then byte + 'a' - 10
     *   else byte + '0'
     * We can make this branchless using this known pattern for turning
     * conditional assignments into cmov* instructions.
     *   x = ( a > b ) ? C : D;
     * into:
     *   x = D;
     *   if ( a > b ) x += C - D;
     * Check the asm output to ensure it, of course.
     */
    uint8_t C = 'a' - 10;
    uint8_t D = '0';
    uint8_t x = D + byte;
    if (byte > 9) x += C - D;
    return x;
}

void datadog_php_uuid_default_ctor(datadog_php_uuid *uuid) {
    for (unsigned i = 0; i != 16; ++i) {
        uuid->data[i] = 0;
    }
}

void datadog_php_uuidv4_bytes_ctor(datadog_php_uuid *uuid, const uint8_t src[static 16]) {
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
     * We want this bit pattern, where X preserves what is there.
     * 0b0100XXXX
     */
    uuid->data[6] = 0x40 | (uuid->data[6] & 0xf);

    /* Set the two most significant bits (bits 6 and 7) of the
     * clock_seq_hi_and_reserved to zero and one, respectively.
     * -----
     * We want this bit pattern, where X preserves what is there.
     * 0b10XXXXXX
     */
    uuid->data[8] = 0x80 | (uuid->data[8] & 0x3f);
}

void datadog_php_uuid_encode32(datadog_php_uuid uuid, char dest[static 32]) {
    char *out = dest;
    for (unsigned i = 0; i < 16; ++i, out += 2) {
        uint8_t in = uuid.data[i];
        char first = nibble_to_hex(in >> UINT8_C(4));
        char second = nibble_to_hex(in & UINT8_C(15));
        *out = first;
        *(out + 1) = second;
    }
}

void datadog_php_uuid_encode36(datadog_php_uuid uuid, char dest[static 36]) {
    /* This could be more efficient if we didn't call to encode32 and then
     * fix it up after, but this is not likely to be performance sensitive.
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
