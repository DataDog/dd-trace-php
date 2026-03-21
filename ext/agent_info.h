#ifndef DD_AGENT_INFO_H
#define DD_AGENT_INFO_H

#include <stddef.h>
#include "Zend/zend_types.h"

void ddtrace_check_agent_info_env(void);
void ddtrace_agent_info_rinit(void);
void ddtrace_get_container_tags_hash(void);

#endif // DD_AGENT_INFO_H
