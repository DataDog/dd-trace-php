#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include <TSRM/TSRM.h>

#include "container_id/container_id.h"

void ddshared_minit(TSRMLS_D);

char *ddshared_container_id(void);

#endif  // DD_TRACE_SHARED_H
