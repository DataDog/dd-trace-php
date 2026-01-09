#pragma once

#include "ddtrace.h"
#include "ddtrace_export.h"

void ddtrace_maybe_add_guessed_endpoint_tag(ddtrace_root_span_data *span);
DDTRACE_PUBLIC zend_string* ddtrace_guess_endpoint_from_url(const char* url, size_t url_len);
