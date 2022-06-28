#include <include/extension.h>
#include <include/frame.h>

bool tea_frame_push(zend_execute_data *frame) {
    zend_function *noop;

    noop = zend_hash_str_find_ptr(EG(function_table), ZEND_STRL("tea\\noop"));
    if (!noop) {
        return false;
    }

    memset(frame, 0, sizeof(zend_execute_data));

    frame->func = noop;

    frame->prev_execute_data = EG(current_execute_data);

    EG(current_execute_data) = frame;
    return true;
}

void tea_frame_pop(zend_execute_data *frame) { EG(current_execute_data) = frame->prev_execute_data; }

ZEND_BEGIN_ARG_INFO_EX(tea_frame_noop_arginfo, 0, 0, 0)
ZEND_END_ARG_INFO()

/* NOOP function used to make fake execute frames.
 *
 * tea\noop(void): null
 */
static PHP_FUNCTION(noop) {
    TEA_EXTENSION_PARAMETERS_UNUSED();

    /* NOOP */
    RETURN_NULL();
}

// clang-format off
static const zend_function_entry tea_frame_functions[] = {
    ZEND_NS_FE("tea", noop, tea_frame_noop_arginfo)
    PHP_FE_END
};
// clang-format on

void tea_frame_sinit(void) { tea_extension_functions(tea_frame_functions); }
