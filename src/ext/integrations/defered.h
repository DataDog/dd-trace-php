#ifndef DD_INTEGRATIONS_DEFERED_H
#define DD_INTEGRATIONS_DEFERED_H
#include "ddtrace_string.h"

struct ddtrace_defered_integration {
    ddtrace_string class_name;
    ddtrace_string fname;
    ddtrace_string loader;
};
typedef struct ddtrace_defered_integration ddtrace_defered_integration;

#define DDTRACE_DEFERED_INTEGRATION_LOADER(class_str, fname_str, loader_str) \
    {                                                                        \
        .class_name =                                                        \
            {                                                                \
                .ptr = class_str,                                            \
                .len = sizeof(class_str) - 1,                                \
            },                                                               \
        .fname =                                                             \
            {                                                                \
                .ptr = fname_str,                                            \
                .len = sizeof(fname_str) - 1,                                \
            },                                                               \
        .loader = {                                                          \
            .ptr = loader_str,                                               \
            .len = sizeof(loader_str) - 1,                                   \
        },                                                                   \
    }
#endif

#define SIZE_OF_DEFERED_LIST(list) (sizeof(list) / sizeof(list[0]))

void dd_load_defered_integration_list(ddtrace_defered_integration *list, size_t size);
