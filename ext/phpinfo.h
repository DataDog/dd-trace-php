#ifndef DATADOG_PHPINFO_H
#define DATADOG_PHPINFO_H

#include <stdbool.h>

// Do not include php.h here: it must come after "config.h", which datadog.h takes care of
struct _zend_module_entry;

// Since 2.0 the tracer, the profiler and Application Security live in a single PHP extension. The phpinfo() output of
// that extension is split in one section per component. Each INI entry registered by the extension is shown in the
// section of the component it belongs to.
typedef enum {
    DATADOG_PHPINFO_INI_TRACER = 1 << 0,   // datadog.trace.*, datadog.* shared settings, ddtrace.*, OTEL_*
    DATADOG_PHPINFO_INI_APPSEC = 1 << 1,   // datadog.appsec.*, datadog.api_security_*
    DATADOG_PHPINFO_INI_PROFILER = 1 << 2, // datadog.profiling.*
} datadog_phpinfo_ini_group;

// Introduction box and a summary of the Datadog components provided by this extension
void datadog_phpinfo(bool appsec_loaded, bool profiler_loaded);
// Tracer section: status, tracer configuration and diagnostics (INI entries are printed separately)
void datadog_phpinfo_tracer(void);
// Prints a heading starting a section of the Datadog output
void datadog_phpinfo_section(const char *title);
// Prints a Directive / Local Value / Master Value table with the INI entries belonging to the given groups (a bitmask of
// datadog_phpinfo_ini_group). Prints nothing if there is no such entry.
void datadog_phpinfo_ini_entries(struct _zend_module_entry *module, unsigned groups);

#endif // DATADOG_PHPINFO_H
