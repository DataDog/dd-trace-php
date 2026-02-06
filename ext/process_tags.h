#ifndef DD_PROCESS_TAGS_H
#define DD_PROCESS_TAGS_H

#include <stdbool.h>
#include "Zend/zend_types.h"
#include "ddtrace_export.h"

// Called at first RINIT to collect process tags
void ddtrace_process_tags_first_rinit(void);

// Called at MSHUTDOWN to free resources
void ddtrace_process_tags_mshutdown(void);

// Check if process tags propagation is enabled
bool ddtrace_process_tags_enabled(void);

// Get the serialized process tags (comma-separated, sorted)
// Returns NULL if disabled or not yet collected
DDTRACE_PUBLIC zend_string *ddtrace_process_tags_get_serialized(void);

#endif // DD_PROCESS_TAGS_H
