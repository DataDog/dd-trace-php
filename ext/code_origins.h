#ifndef DD_CODE_ORIGINS_H
#define DD_CODE_ORIGINS_H

#include "span.h"

void ddtrace_add_code_origin_information(ddtrace_span_data *span, int skip_frames);
void ddtrace_maybe_add_code_origin_information(ddtrace_span_data *span);
void ddtrace_code_origins_minit();
void ddtrace_code_origins_shutdown();

#endif // DD_CODE_ORIGINS_H
