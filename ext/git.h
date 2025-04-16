#ifndef DD_GIT_H
#define DD_GIT_H
#include <Zend/zend_types.h>
#include <stdbool.h>

void ddtrace_inject_git_metadata(zval *git_metadata_zv);
void ddtrace_git_metadata_dtor(zval *val);
void ddtrace_clean_git_object(void);

#endif // DD_GIT_H