#include <zai_string/string.h>
#include <components/log/log.h>

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    if (str == NULL) {
        return (ddog_CharSlice){ .len = 0, .ptr = NULL };
    }
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

static inline ddog_CharSlice dd_zai_string_to_CharSlice(zai_string str) {
    return (ddog_CharSlice){ .len = str.len, .ptr = str.ptr };
}

static inline zend_string *dd_CharSlice_to_zend_string(ddog_CharSlice slice) {
    return zend_string_init(slice.ptr, slice.len, 0);
}

static inline bool datadog_ffi_try(const char *msg, ddog_MaybeError maybe_error) {
    if (maybe_error.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice error = ddog_Error_message(&maybe_error.some);
        LOG(ERROR, "%s: %.*s", msg, (int) error.len, error.ptr);
        ddog_MaybeError_drop(maybe_error);
        return false;
    }
    return true;
}
