#ifndef HAVE_TEA_ERROR_H
#define HAVE_TEA_ERROR_H

#include "common.h"

/* Returns true if 'error_type' equals the last error type and 'msg' exactly
 * matches the last error message from PHP globals.
 */
static inline bool tea_error_eq(int error_type, const char *msg TEA_TSRMLS_DC) {
    if (PG(last_error_type) != error_type || PG(last_error_message) == NULL) return false;
#if PHP_VERSION_ID >= 80000
    return strcmp(msg, ZSTR_VAL(PG(last_error_message))) == 0;
#else
    return strcmp(msg, PG(last_error_message)) == 0;
#endif
}

/* Returns true if all of the globals associated with the last error are zeroed
 * out.
 */
static inline bool tea_error_is_empty(TEA_TSRMLS_D) {
    return PG(last_error_type) == 0 && PG(last_error_lineno) == 0 && PG(last_error_message) == NULL &&
           PG(last_error_file) == NULL;
}
#endif
