#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include <TSRM/TSRM.h>

#include "datadog/string.h"

void ddshared_minit(TSRMLS_D);
void ddshared_mshutdown(void);

datadog_string *ddshared_container_id(void);

#endif  // DD_TRACE_SHARED_H
