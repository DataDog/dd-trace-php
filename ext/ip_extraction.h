#ifndef DD_IP_EXTRACTION_H
#define DD_IP_EXTRACTION_H

#include <php.h>

void dd_ip_extraction_startup(void);
void ddtrace_extract_ip_from_headers(zval *server, zend_array *meta);

#endif
