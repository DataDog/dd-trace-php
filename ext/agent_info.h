#ifndef DATADOG_AGENT_INFO_H
#define DATADOG_AGENT_INFO_H

#include <stddef.h>
#include "Zend/zend_types.h"

void datadog_agent_info_rinit(void);
void datadog_apply_agent_info(void);

#endif // DATADOG_AGENT_INFO_H
