#ifndef DD_UHOOK_LINE_H
#define DD_UHOOK_LINE_H

#include <php.h>
#include "../span.h"

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

/* A span covering a line range, for any consumer of zai_line_hooks.
   Spans opened this way share one per-frame record, so ddtrace_uhook_line_close_frame() and the generator suspend/resume hooks cover all of them.*/
typedef struct {
    ddtrace_span_data *span;                /* owned; a dummy if a consumer asked for one that could not be opened */
    ddtrace_span_stack *prior_stack;        /* owned; switched back to when the span closes */
    struct dd_line_frame_data *frame_state; /* set while a real span is open */
} ddtrace_line_span;

ddtrace_span_data *ddtrace_line_span_open(ddtrace_line_span *line_span, zend_execute_data *frame, ddtrace_span_stack *parent);

/* Close (keep) or drop the span, restore the stacks it switched, and release the frame record when its last span goes. */
void ddtrace_line_span_finish(ddtrace_line_span *line_span, bool keep);

/* The range's generator yielded or resumed. Repeats are no-ops. */
void ddtrace_line_span_suspend(ddtrace_line_span *line_span);
void ddtrace_line_span_resume(ddtrace_line_span *line_span);

#endif  // DD_UHOOK_LINE_H
