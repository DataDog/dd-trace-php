#ifndef DD_GIT_H
#define DD_GIT_H
#include <Zend/zend_types.h>
#include <stdbool.h>

struct ddtrace_git_metadata {
    zend_string *commit_sha;
    zend_string *repository_url;
    bool called_once;
};
typedef struct ddtrace_git_metadata ddtrace_git_metadata;

void ddtrace_inject_git_metadata(zval* meta, bool is_root_span);

#endif // DD_GIT_H