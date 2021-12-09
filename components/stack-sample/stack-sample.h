#ifndef DATADOG_PHP_STACK_SAMPLE_H
#define DATADOG_PHP_STACK_SAMPLE_H

#include <components/string_view/string_view.h>
#include <stdint.h>

#define DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH 101u
static const uint16_t datadog_php_stack_sample_max_depth = DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH;

/**
 * A stack sample represents a stack sample in a serialized form that is not
 * aware of any language runtime specific things. This layout could be more
 * efficient. Each frame holds the function/method name, file, and line. Not all
 * frames will have all members.
 *
 * Treat this as opaque so as it's easier to test structure-of-arrays vs
 * array-of-structures and other layout optimizations.
 */
typedef struct datadog_php_stack_sample_s {
    uint16_t depth;
    uint16_t buffer_len;

    /* We save quite a bit of space by using offsets into the buffer instead of
     * storing full pointers (16 bit vs 64 bit).
     * We leave room for 1 more frame than datadog_php_stack_sample_max_depth for
     * a summary frame ($x more frames).
     */
    uint16_t function_off[DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1];
    uint16_t function_len[DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1];
    uint16_t file_off[DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1];
    uint16_t file_len[DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1];
    int64_t lineno[DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1];

    /* The strings need to point into this buffer. It's sized so that there are
     * 64 bytes per entry, which on average may not be enough, but does allow for
     * deeper stacks if the function and file names are on the shorter side.
     */
    char buffer[(DATADOG_PHP_STACK_SAMPLE_MAX_DEPTH + 1) * 64u];
} datadog_php_stack_sample;

typedef struct datadog_php_stack_sample_frame_s {
    datadog_php_string_view function;
    datadog_php_string_view file;
    int64_t lineno;
} datadog_php_stack_sample_frame;

void datadog_php_stack_sample_ctor(datadog_php_stack_sample *);
uint16_t datadog_php_stack_sample_depth(const datadog_php_stack_sample *sample);
void datadog_php_stack_sample_dtor(datadog_php_stack_sample *);

bool datadog_php_stack_sample_try_add(datadog_php_stack_sample *, datadog_php_stack_sample_frame);

/**
 * A stack sample iterator can be used to iterate across a sample, re-assembling
 * each frame from its serialized layout.
 */
typedef struct datadog_php_stack_sample_iterator {
    const datadog_php_stack_sample *sample;
    uint16_t depth;
} datadog_php_stack_sample_iterator;

datadog_php_stack_sample_iterator datadog_php_stack_sample_iterator_ctor(const datadog_php_stack_sample *);

void datadog_php_stack_sample_iterator_dtor(datadog_php_stack_sample_iterator *iterator);

bool datadog_php_stack_sample_iterator_valid(datadog_php_stack_sample_iterator *iterator);

uint16_t datadog_php_stack_sample_iterator_depth(const datadog_php_stack_sample_iterator *iterator);

datadog_php_stack_sample_frame datadog_php_stack_sample_iterator_frame(datadog_php_stack_sample_iterator *iterator);

void datadog_php_stack_sample_iterator_next(datadog_php_stack_sample_iterator *iterator);

#endif  // DATADOG_PHP_STACK_SAMPLE_H
