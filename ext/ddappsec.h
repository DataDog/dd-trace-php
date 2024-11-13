#ifndef DD_APPSEC_H
#define DD_APPSEC_H

#include "ddtrace_export.h"

#define DD_TAG_P_APPSEC "_dd.p.appsec"

void ddtrace_appsec_minit();
DDTRACE_PUBLIC void ddtrace_emit_asm_event();

#endif  // DD_APPSEC_H
