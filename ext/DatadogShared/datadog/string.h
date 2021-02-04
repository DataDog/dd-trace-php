#ifndef DATADOG_STRING_H
#define DATADOG_STRING_H

#include <stddef.h>

struct datadog_string {
    // unsigned long h; TODO Make a new type datadog_hashed_string?
    size_t len;
    char val[1];
};
typedef struct datadog_string datadog_string;

datadog_string *datadog_string_alloc(size_t len);
datadog_string *datadog_string_init(const char *str, size_t len);

void datadog_string_free(datadog_string *str);

#endif  // DATADOG_STRING_H
