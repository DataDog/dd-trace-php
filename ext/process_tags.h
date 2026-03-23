#ifndef DD_PROCESS_TAGS_H
#define DD_PROCESS_TAGS_H

#include <stdbool.h>
#include "Zend/zend_types.h"
#include "ddtrace_export.h"
#include "components-rs/common.h"


// Called at first RINIT to collect process tags
void ddtrace_process_tags_first_rinit(void);
// Reload process tags in current request
void ddtrace_process_tags_reload(void);

// Called at MSHUTDOWN to free resources
void ddtrace_process_tags_mshutdown(void);

// Check if process tags propagation is enabled
bool ddtrace_process_tags_enabled(void);

// Get the serialized process tags (comma-separated, sorted)
// Returns NULL if disabled or not yet collected
DDTRACE_PUBLIC zend_string *ddtrace_process_tags_get_serialized(void);

// Get a pointer to the process tags Vec<Tag>
// Returns a pointer to an empty Vec if disabled or not yet collected
const ddog_Vec_Tag *ddtrace_process_tags_get_vec(void);

// Set the container tags hash
void ddtrace_process_tags_set_container_tags_hash(zend_string *hash);

// Get the base hash which is the hash of container_tags and process_tags
// Returns NULL if disabled or not yet computed
zend_string *ddtrace_process_tags_get_base_hash(void);

#endif // DD_PROCESS_TAGS_H
