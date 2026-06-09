#ifndef DATADOG_ENDPOINTS_H
#define DATADOG_ENDPOINTS_H

#include <components-rs/common.h>

char *datadog_agent_url(void);
char *datadog_dogstatsd_url(void);
ddog_Endpoint *datadog_otel_metrics_endpoint(void);
ddog_Endpoint *datadog_otel_traces_endpoint(void);
char *datadog_otel_traces_url(void);

#endif // DATADOG_ENDPOINTS_H
