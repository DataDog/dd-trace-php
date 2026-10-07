#ifndef DD_UHOOK_LINE_H
#define DD_UHOOK_LINE_H

#include <php.h>

extern zend_class_entry *ddtrace_line_hook_data_ce;
zend_object *ddtrace_line_hook_data_create(zend_class_entry *class_type);
void ddtrace_line_hook_data_minit(void);

void ddtrace_uhook_line_rinit(void);
void ddtrace_uhook_line_rshutdown(void);

/* Close nested line spans before a function hook closes its own span. */
void ddtrace_uhook_line_close_frame(zend_execute_data *frame);

/* Keep per-resumption function spans outside line spans, regardless of guard registration order. */
void ddtrace_uhook_line_suspend_frame(zend_execute_data *frame);
void ddtrace_uhook_line_resume_frame(zend_execute_data *frame);

/* Returns a negative hook ID, or 0 if line hooks are unavailable in this request. */
zend_long ddtrace_uhook_line_install(zend_string *file, zend_long line, zend_long end_line, zend_object *begin, zend_object *end);

/* Returns a message if the range partially overlaps an installed one in the same file, else NULL. */
const char *ddtrace_uhook_line_conflict(zend_string *file, zend_long line, zend_long end_line);

/* True if the id belongs to the line-hook space and was handled here, so DDTrace\remove_hook() can dispatch on it. */
bool ddtrace_uhook_line_remove(zend_long id);

#endif  // DD_UHOOK_LINE_H
