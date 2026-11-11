#ifndef ASM_EVENT_H
#define ASM_EVENT_H

#include "ddtrace.h"
#include <ext/datadog_export.h>

void ddtrace_emit_asm_event();
bool ddtrace_asm_event_emitted();
void ddtrace_asm_event_rinit();


#endif  // ASM_EVENT_H
