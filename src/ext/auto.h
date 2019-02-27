#ifndef DD_AUTO_H
#define DD_AUTO_H

#include <Zend/zend_types.h>
#include <stdint.h>

typedef struct _ddtrace_auto_stats_t {
    long avg_time;
    uint32_t count;
} ddtrace_auto_stats_t;

void ddtrace_auto_inject();
ddtrace_auto_stats_t* ddtrace_auto_record_fetch(zend_execute_data *ex, const char *function_name, size_t function_name_length TSRMLS_DC);
void ddtrace_auto_minit(TSRMLS_D);
void ddtrace_auto_mdestroy(TSRMLS_D);

#endif // DD_AUTO_H
