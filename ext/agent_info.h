#ifndef DATADOG_AGENT_INFO_H
#define DATADOG_AGENT_INFO_H

#include <stddef.h>
#include "Zend/zend_types.h"

void datadog_agent_info_rinit(void);
void datadog_apply_agent_info(void);

// Removable v0.4->v1 bolt-on for the in-process (<=8.2) sender: true when the agent advertises the
// /v1.0/traces endpoint in its /info payload. Deleting the V1 in-process transcode == deleting this.
bool ddtrace_agent_supports_v1_traces(void);

#endif // DATADOG_AGENT_INFO_H
