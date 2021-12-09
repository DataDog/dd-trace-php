#ifndef DATADOG_PHP_UUID
#define DATADOG_PHP_UUID

#include <stdalign.h>
#include <stdint.h>

#if __cplusplus
/* C++ doesn't support this form of static: char src[static 16]
 * so we expand it to nothing. */
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

/**
 * Represents a UUID abstractly, but mostly for the v4 variant which uses
 * random bytes.
 */
typedef struct datadog_php_uuid {
    // Since this is a 16-byte type, let's use a 16 byte alignment
    alignas(16) uint8_t data[16];
} datadog_php_uuid;

// This is the "nil" UUID
#define DATADOG_PHP_UUID_INIT                              \
    {                                                      \
        { 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0 } \
    }

/**
 * Constructs a "nil" UUID in the memory of `uuid`.
 */
void datadog_php_uuid_default_ctor(datadog_php_uuid *uuid);

/**
 * Creates a UUIDv4 from the provided bytes. This decouples the UUIDv4
 * invariants from the algorithm that generates random numbers, so be cautious
 * that you pass random bytes to it (or are testing specific bit patterns).
 *
 * @param uuid
 * @param src At least 16 random bytes
 */
void datadog_php_uuidv4_bytes_ctor(datadog_php_uuid *uuid, const uint8_t src[C_STATIC(16)]);

/**
 * Encodes the `uuid` into a 32 character ASCII string. This is the same as the
 * 36 character ASCII string defined by RFC 4122 except with the '-' chars
 * removed. A null terminator is NOT written.
 *
 * @param uuid The UUID to encode.
 * @param dest A char buffer at least 32 chars in length.
 */
void datadog_php_uuid_encode32(datadog_php_uuid uuid, char dest[C_STATIC(32)]);

/**
 * Encodes the `uuid` into a 36 character ASCII string as defined by RFC 4122
 * (https://tools.ietf.org/html/rfc4122#section-4.1), and stores the result in
 * `dest`. A null terminator is NOT written.
 *
 * @param uuid The UUID to encode.
 * @param dest A char buffer at least 36 chars in length.
 */
void datadog_php_uuid_encode36(datadog_php_uuid uuid, char dest[C_STATIC(36)]);

#undef C_STATIC

#endif  // DATADOG_PHP_UUID
