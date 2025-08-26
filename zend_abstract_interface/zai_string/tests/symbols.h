#include "../string.h"
#include <Zend/zend_API.h>

static inline bool zai_test_call_global_with_0_params(zai_str *name, zval *rv) {
    zval fn_name;
    ZVAL_STRINGL(&fn_name, name->ptr, name->len);
    int ret = call_user_function(EG(function_table), NULL, &fn_name, rv, 0, NULL);
    zval_ptr_dtor(&fn_name);
    return ret == SUCCESS;
}
