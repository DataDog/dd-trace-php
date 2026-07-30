#pragma once

#include <php.h>
#include <stdint.h>

void dd_appsec_otel_context_startup(void);
void dd_appsec_otel_context_gshutdown(void);

void dd_appsec_otel_context_refresh(zend_string *legacy_service,
    zend_string *legacy_environment, zend_string *legacy_version,
    zend_string *legacy_runtime_id);

zend_string *dd_appsec_otel_context_service(void);
zend_string *dd_appsec_otel_context_environment(void);
zend_string *dd_appsec_otel_context_version(void);
zend_string *dd_appsec_otel_context_runtime_id(void);
uint64_t dd_appsec_otel_context_generation(void);
