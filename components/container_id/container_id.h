#ifndef DATADOG_PHP_CONTAINER_ID_H
#define DATADOG_PHP_CONTAINER_ID_H

#include <stddef.h>
#include <sys/types.h>
// comment to ensure both defs are before regex.h
#include <regex.h>
#include <stdbool.h>

/* Fargate 1.4+ IDs match:
 *    [0-9a-f]{32}-\d+
 * Assuming it is possible for '\d+' to be a single digit, the shortest
 * expected ID would be 32 + 1 + 1 = 34.
 * Assuming '\d+' is an unsigned 64-bit integer, the maximum possible value is
 * 18446744073709551615 which is 20 characters long. Thus the longest possible
 * Fargate 1.4+ ID would be 32 + 1 + 20 = 53 characters long.
 *
 * Most of the container IDs match:
 *    [0-9a-f]{64}
 * This makes the longest expected ID 64 characters long.
 */
#define DATADOG_PHP_CONTAINER_ID_MAX_LEN 64

typedef struct datadog_php_container_id_parser {
    regex_t line_regex;
    regex_t task_regex;
    regex_t container_regex;
    bool (*is_valid_line)(struct datadog_php_container_id_parser *parser, const char *line);
    bool (*extract_task_id)(struct datadog_php_container_id_parser *parser, char *buf, const char *line);
    bool (*extract_container_id)(struct datadog_php_container_id_parser *parser, char *buf, const char *line);
} datadog_php_container_id_parser;

bool datadog_php_container_id_parser_ctor(datadog_php_container_id_parser *parser);
bool datadog_php_container_id_parser_dtor(datadog_php_container_id_parser *parser);

bool datadog_php_container_id_from_file(char *buf, const char *file);

#endif  // DATADOG_PHP_CONTAINER_ID_H
