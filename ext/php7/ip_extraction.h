#ifndef DD_IP_EXTRACTION_H
#define DD_IP_EXTRACTION_H

#include <php.h>

void dd_ip_extraction_startup(void);

// Since the headers looked at can in principle be forged, it's very much
// recommended that a datadog.appsec.ipheader is set to a header that the server
// guarantees cannot be forged
void ddtrace_extract_ip_from_headers(zval *server, zend_array *meta);

bool ddtrace_on_ip_header_change(zval *old_value, zval *new_value);

#endif
