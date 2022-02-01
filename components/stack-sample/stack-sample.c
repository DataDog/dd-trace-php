#include "stack-sample.h"

#include <string.h>

/* Done in the impl instead of the header because on CentOS 6 in gnu++11 mode
 * it fails in the header. The header gets included in C++ mode for testing. */
_Static_assert(sizeof(struct datadog_php_stack_sample_s) < 8192u,
               "size of datadog_php_stack_sample should be less than 8KiB");

typedef datadog_php_stack_sample stack_sample_t;
typedef datadog_php_stack_sample_frame stack_sample_frame_t;
typedef datadog_php_stack_sample_iterator stack_sample_iterator_t;
typedef datadog_php_string_view string_view_t;

static void stack_sample_default_ctor(stack_sample_t *sample) {
    memset(sample, 0, sizeof *sample);

    // we store an empty string with null terminator at position 0
    sample->buffer_len = 1;
}

void datadog_php_stack_sample_ctor(datadog_php_stack_sample *sample) { stack_sample_default_ctor(sample); }

uint16_t datadog_php_stack_sample_depth(const datadog_php_stack_sample *sample) { return sample->depth; }

void datadog_php_stack_sample_dtor(datadog_php_stack_sample *sample) { stack_sample_default_ctor(sample); }

static bool try_add_string(stack_sample_t *sample, string_view_t string, uint16_t *offset) {
    // we pack all empty strings into offset 0
    if (string.len == 0) {
        *offset = 0;
        return true;
    }

    // ensure there is room for the string
    if (sample->buffer_len + string.len + 1 <= sizeof(sample->buffer)) {
        uint16_t off = sample->buffer_len;
        memcpy(&sample->buffer[off], string.ptr, string.len);

        // null-terminate the string; we rely on ctor's memzero for this and just +1
        sample->buffer_len += string.len + 1;
        *offset = off;
        return true;
    } else {
        return false;
    }
}

bool datadog_php_stack_sample_try_add(stack_sample_t *sample, stack_sample_frame_t frame) {
    uint16_t depth = sample->depth;
    if (depth >= datadog_php_stack_sample_max_depth) {
        return false;
    }

    uint16_t function_off;
    if (!try_add_string(sample, frame.function, &function_off)) {
        return false;
    }
    sample->function_off[depth] = function_off;
    sample->function_len[depth] = frame.function.len;

    uint16_t file_off;
    if (!try_add_string(sample, frame.file, &file_off)) {
        return false;
    }
    sample->file_off[depth] = file_off;
    sample->file_len[depth] = frame.file.len;

    sample->lineno[depth] = frame.lineno;
    ++sample->depth;

    return true;
}

stack_sample_iterator_t datadog_php_stack_sample_iterator_ctor(const stack_sample_t *sample) {
    stack_sample_iterator_t iterator = {
        .sample = sample,
        .depth = 0,
    };
    return iterator;
}

void datadog_php_stack_sample_iterator_dtor(stack_sample_iterator_t *iterator) {
    iterator->sample = NULL;
    iterator->depth = 0;
}

void datadog_php_stack_sample_iterator_rewind(stack_sample_iterator_t *iterator) { iterator->depth = 0; }

bool datadog_php_stack_sample_iterator_valid(stack_sample_iterator_t *iterator) {
    return iterator->sample && iterator->depth < iterator->sample->depth;
}

uint16_t datadog_php_stack_sample_iterator_depth(const stack_sample_iterator_t *iterator) { return iterator->depth; }

stack_sample_frame_t datadog_php_stack_sample_iterator_frame(stack_sample_iterator_t *iterator) {
    const stack_sample_t *sample = iterator->sample;
    uint16_t depth = iterator->depth;

    const char *function = &sample->buffer[sample->function_off[depth]];
    size_t function_len = sample->function_len[depth];

    const char *file = &sample->buffer[sample->file_off[depth]];
    size_t file_len = sample->file_len[depth];
    stack_sample_frame_t frame = {
        .function = {function_len, function},
        .file = {file_len, file},
        .lineno = sample->lineno[depth],
    };
    return frame;
}

void datadog_php_stack_sample_iterator_next(stack_sample_iterator_t *iterator) { ++iterator->depth; }
