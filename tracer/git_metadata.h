#ifndef DDTRACE_GIT_H
#define DDTRACE_GIT_H
#include <Zend/zend_types.h>

void ddtrace_inject_git_metadata(zval *carrier);
void ddtrace_clean_git_object(void);

#endif // DDTRACE_GIT_H
