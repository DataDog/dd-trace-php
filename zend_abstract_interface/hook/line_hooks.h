#ifndef ZAI_LINE_HOOKS_H
#define ZAI_LINE_HOOKS_H

#include <Zend/zend_compile.h>
#include <Zend/zend_types.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

/* Line hooking is three layers:

     interceptor/line_hook.c   arms a single opline by replacing its VM handler                                 zai_line_hook_*
     hook/line_hooks.c         resolves file:line to oplines and owns definitions, ranges and per-frame state   zai_line_hooks_*
     the consumer              decides what a hooked line means

   This file is the middle layer, and it is to install_line_hook() what hook.h is to install_hook(): it knows nothing about closures, sandboxes or spans.
   A consumer supplies two C callbacks, an auxiliary pointer shared by every invocation, and a payload size; everything the consumer wants to carry from a begin to its end lives in that payload.
   tracer/hook/uhook_line.c is one consumer. */

/* Shared by every invocation of one definition.
   The dtor runs when the definition is released, which can be after the request that installed it has begun shutting down. */
typedef struct {
    void *data;
    void (*dtor)(void *data);
} zai_line_hooks_aux;

#define ZAI_LINE_HOOKS_AUX_UNUSED ((zai_line_hooks_aux){.data = NULL, .dtor = NULL})
#define ZAI_LINE_HOOKS_AUX(data_, dtor_) ((zai_line_hooks_aux){.data = (data_), .dtor = (dtor_)})

typedef struct zai_line_invocation zai_line_invocation;

/* Runs before the resolved begin line executes.
   `dynamic` is this invocation's payload, zeroed.
   `invocation` is valid until this callback returns, including across fiber suspension.

   Return false to abandon the invocation: its payload is freed and no end is delivered, including for a range already opened eagerly.
   A consumer that declines -- because it is suppressing re-entry, or because it never opened a range it wanted -- owns whatever it put in the payload and must release it first. */
typedef bool (*zai_line_hooks_begin)(zai_line_invocation *invocation, zend_execute_data *frame, const zend_op *opline, uint32_t line, void *auxiliary, void *dynamic);

/* Runs after the end line executes, or when control leaves the range by any other path.
   `line` is the definition's own end line when the frame is unwinding and no opline reached an exit site. */
typedef void (*zai_line_hooks_end)(zend_execute_data *frame, uint32_t line, void *auxiliary, void *dynamic);

/* The generator owning this range yielded, or was resumed.
   Delivered once per range open in the frame, so a consumer aggregating per frame must tolerate repeats.
   Must not execute PHP: a range closing underneath the walk would invalidate it. */
typedef void (*zai_line_hooks_frame_event)(zend_execute_data *frame, void *auxiliary, void *dynamic);

/* Installs a hook on `file`, which is matched as a path suffix, from `line` to `end_line` inclusive.
   `eager_range` opens a range at every begin, so an end is always delivered; without it the invocation is begin-only unless it calls zai_line_hooks_open_range().
   Returns a negative id, or 0 outside a request. */
zend_long zai_line_hooks_install(zend_string *file, uint32_t line, uint32_t end_line,
                                zai_line_hooks_begin begin, zai_line_hooks_end end,
                                bool eager_range, zai_line_hooks_aux aux, size_t dynamic);

/* As above, plus the generator events.
   A probe that does not care when its frame suspends -- a metrics probe that only counts begins, say -- installs through the plain entry point and is never called for them. */
zend_long zai_line_hooks_install_generator(zend_string *file, uint32_t line, uint32_t end_line,
                                          zai_line_hooks_begin begin, zai_line_hooks_frame_event suspend, zai_line_hooks_frame_event resume, zai_line_hooks_end end,
                                          bool eager_range, zai_line_hooks_aux aux, size_t dynamic);

/* True if the id belongs to the line-hook space, so a caller sharing an id space can dispatch on it.
   New begins stop at once; ranges already open still close, and the definition goes with the last of them. */
bool zai_line_hooks_remove(zend_long id);

/* A message if the range partially overlaps an installed one whose file suffix could name the same file, else NULL.
   Ranges must nest or be disjoint. */
const char *zai_line_hooks_conflict(zend_string *file, uint32_t line, uint32_t end_line);

/* From inside a begin callback, for its own invocation: keep the range open so an end is delivered, arming the exit sites the definition did not need until now.
   False if the frame cannot hold open ranges. */
bool zai_line_hooks_open_range(zai_line_invocation *invocation);

/* Close every range open in a frame, for a consumer that must finish before the frame does. */
void zai_line_hooks_close_frame(zend_execute_data *frame);

/* Re-resolve pending definitions against a freshly compiled file. */
void zai_line_hooks_file_compiled(zend_op_array *op_array);

void zai_line_hooks_rinit(void);
void zai_line_hooks_rshutdown(void);

#endif  // ZAI_LINE_HOOKS_H
