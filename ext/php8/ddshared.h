#ifndef DD_TRACE_SHARED_H
#define DD_TRACE_SHARED_H

#include "datadog/container_id.h"

#define DDSHARED_CONTAINER_ID_LEN DATADOG_CONTAINER_ID_LEN

void ddshared_minit(void);

char *ddshared_container_id(void);

#endif  // DD_TRACE_SHARED_H
