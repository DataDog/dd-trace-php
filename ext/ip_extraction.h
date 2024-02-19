#ifndef DD_IP_EXTRACTION_H
#define DD_IP_EXTRACTION_H

#include <php.h>
#include <zai_string/string.h>
#include <ddtrace_export.h>

void dd_ip_extraction_startup(void);
bool ddtrace_parse_client_ip_header_config(zai_str value, zval *decoded_value, bool persistent);
DDTRACE_PUBLIC zend_string *ddtrace_ip_extraction_find(zval *server);
void ddtrace_extract_ip_from_headers(zval *server, zend_array *meta);

#endif
