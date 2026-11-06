#ifndef DATADOG_ENDPOINTS_H
#define DATADOG_ENDPOINTS_H

#include <components-rs/common.h>

char *datadog_agent_url(void);
char *datadog_dogstatsd_url(void);
#ifdef SIDECAR
ddog_Endpoint *datadog_otel_metrics_endpoint(void);
#endif

#endif // DATADOG_ENDPOINTS_H
