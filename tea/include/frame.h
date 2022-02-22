#ifndef HAVE_TEA_FRAME_H
#define HAVE_TEA_FRAME_H

#include "common.h"

/* Inserts a fake frame scoped to the 'tea\noop' internal function into the
 * active PHP execution stack. If there is no active execution context, the
 * fake frame will be the first frame in the stack. If there is an existing
 * stack active in the executor globals, the fake frame will be added to the
 * top of the stack.
 *
 * Motivation: sometimes it is necessary to emulate code execution during
 * runtime (e.g. a custom opcode handler) or to create an execution context
 * for certain functionality (e.g. throw an exception when there is no active
 * execution context). A fake frame provides an execution context for these
 * cases.
 */
bool tea_frame_push(zend_execute_data *frame TEA_TSRMLS_DC);

/* Removes a fake frame from the active execution context. This should be done
 * before the parent frame closes.
 */
void tea_frame_pop(zend_execute_data *frame TEA_TSRMLS_DC);
#endif
