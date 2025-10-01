#ifndef DD_PROCESS_TAGS_H
#define DD_PROCESS_TAGS_H

#include <stdbool.h>
#include <php.h>

/**
 * Initialize process tags collection. 
 * Should be called once during MINIT phase.
 */
void ddtrace_process_tags_minit(void);

/**
 * Shutdown process tags.
 * Should be called during MSHUTDOWN phase.
 */
void ddtrace_process_tags_mshutdown(void);

/**
 * Check if process tags propagation is enabled.
 * 
 * @return true if DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED is true
 */
bool ddtrace_process_tags_enabled(void);

/**
 * Get the serialized process tags as a comma-separated string.
 * Format: key1:value1,key2:value2,...
 * Keys are sorted alphabetically.
 * 
 * @return zend_string* containing serialized tags, or NULL if disabled or empty
 */
zend_string *ddtrace_process_tags_get_serialized(void);

#endif  // DD_PROCESS_TAGS_H


