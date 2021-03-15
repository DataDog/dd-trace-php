#ifndef DATADOG_PHP_CONTAINER_ID_H
#define DATADOG_PHP_CONTAINER_ID_H

/* The shortest possible ID would be a Fargate 1.4+ ID that matches:
 *    [0-9a-f]{32}-\d+
 * Assuming it is possible for '\d+' to be a single digit, the shortest
 * expected ID would be 32 + 1 + 1 = 34.
 */
#define DATADOG_PHP_CONTAINER_ID_MIN_LEN 34

/* Fargate 1.4+ IDs match:
 *    [0-9a-f]{32}-\d+
 * Assuming '\d+' is an unsigned 64-bit integer, the maximum possible value is
 * 18446744073709551615 which is 20 characters long. Thus the longest possible
 * Fargate 1.4+ ID would be 32 + 1 + 20 = 53 characters long.
 *
 * Most of the container IDs match:
 *    [0-9a-f]{64}
 * This makes the longest expected ID 64 characters long.
 */
#define DATADOG_PHP_CONTAINER_ID_MAX_LEN 64

void datadog_php_container_id(char *buf, const char *file);

#endif  // DATADOG_PHP_CONTAINER_ID_H
