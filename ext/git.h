#ifndef DATADOG_GIT_H
#define DATADOG_GIT_H
#include <Zend/zend_types.h>
#include <stdbool.h>

// Returns git strings for the current request, caching on first call.
// Output strings are borrowed (owned by DATADOG_G, caller must not release).
// Returns true if at least one string is non-NULL.
bool datadog_get_git_metadata(zend_string **out_commit, zend_string **out_repo);
void datadog_git_rshutdown(void);
void datadog_git_metadata_dtor(zval *val);

#endif // DATADOG_GIT_H