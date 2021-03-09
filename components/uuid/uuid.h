#ifndef DATADOG_PHP_UUID
#define DATADOG_PHP_UUID

#include <stdint.h>

typedef struct datadog_php_uuid {
    // Since this is a 16-byte type, let's use a 16 byte alignment
    _Alignas(16) uint8_t data[16];
} datadog_php_uuid;

#define DATADOG_PHP_UUID_INIT                              \
    {                                                      \
        { 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0 } \
    }

void datadog_php_uuid_default_ctor(datadog_php_uuid *);

/**
 * Creates a UUIDv4 from the provided bytes. This decouples the UUIDv4
 * invariants from the algorithm that generates random numbers, so be cautious
 * that you pass random bytes to it (or are testing specific bit patterns).
 *
 * @param uuid
 * @param src At least 16 random bytes
 */
void datadog_php_uuidv4_bytes_ctor(datadog_php_uuid *uuid, const uint8_t src[]);

/**
 * Encodes the `uuidv4` into a 32 character ASCII string.
 * @param uuid The UUIDv4 to encode.
 * @param dest A buffer at least 32 chars in length.
 */
void datadog_php_uuid_encode32(datadog_php_uuid uuid, char *dest);

/**
 * Encodes the `uuidv4` into a 36 character ASCII string as defined by RFC 4122
 * (https://tools.ietf.org/html/rfc4122#section-4.1), and stores the result in
 * `dest`.
 *
 * @param uuid The UUIDv4 to encode.
 * @param dest A buffer at least 36 chars in length.
 */
void datadog_php_uuid_encode36(datadog_php_uuid uuid, char *dest);

#endif  // DATADOG_PHP_UUID
