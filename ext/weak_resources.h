#ifndef DD_WEAK_RESOURCES_H
#define DD_WEAK_RESOURCES_H

#include <Zend/zend_types.h>

void ddtrace_weak_resouces_rinit(void);
void ddtrace_weak_resouces_rshutdown(void);
void ddtrace_weak_resource_update(zend_resource *rsrc, zend_string *key, zval *data);
zval *ddtrace_weak_resource_get(zend_resource *rsrc, zend_string *key);

#endif // DD_WEAK_RESOURCES_H
