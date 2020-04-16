#include "ddtrace_string.h"

extern inline bool ddtrace_isspace(unsigned char c);
extern inline char *ddtrace_ltrim(char *restrict begin, char *restrict end);
extern inline char *ddtrace_rtrim(char *restrict begin, char *restrict end);
extern inline ddtrace_string ddtrace_trim(ddtrace_string src);
extern inline bool ddtrace_string_equals(ddtrace_string a, ddtrace_string b);
