#ifndef DD_AGENT_INFO_H
#define DD_AGENT_INFO_H

#include <stddef.h>
#include "Zend/zend_types.h"

void ddtrace_agent_info_rinit(void);
void ddtrace_apply_agent_info(void);

#endif // DD_AGENT_INFO_H
