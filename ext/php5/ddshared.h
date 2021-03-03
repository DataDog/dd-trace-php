#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include <TSRM/TSRM.h>

#include "datadog/container_id.h"

#define DDSHARED_CONTAINER_ID_LEN DATADOG_CONTAINER_ID_LEN

void ddshared_minit(TSRMLS_D);

char *ddshared_container_id(void);

#endif  // DD_TRACE_SHARED_H
