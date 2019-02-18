#include <php.h>
#include "logging.h"

void ddtrace_log_errf(const char *format, ...)
{
	va_list args;
	char *buffer;
	size_t size;

	va_start(args, format);
	size = vspprintf(&buffer, 0, format, args);
	ddtrace_log_err(buffer);

    efree(buffer);
	va_end(args);
}
