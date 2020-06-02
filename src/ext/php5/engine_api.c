#include "engine_api.h"

int ddtrace_call_function(const char *name, size_t name_len, zval **retval, int argc, zval **argv[] TSRMLS_DC) {
    zval *fname;
    MAKE_STD_ZVAL(fname);
    ZVAL_STRINGL(fname, name, name_len, 1);
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    int result = zend_fcall_info_init(fname, IS_CALLABLE_CHECK_SILENT, &fci, &fcc, NULL, NULL TSRMLS_CC);
    if (result == SUCCESS) {
        fci.retval_ptr_ptr = retval;
        fci.params = argv;
        fci.no_separation = 0;  // allow for by-ref args
        fci.param_count = argc;
        result = zend_call_function(&fci, &fcc TSRMLS_CC);
    }
    zval_dtor(fname);
    efree(fname);
    return result;
}
