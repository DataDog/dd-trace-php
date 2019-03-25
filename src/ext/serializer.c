#include <php.h>
#include <stdio.h>

int ddtrace_serialize_trace(zval *trace, zval *retval) {
    ZVAL_STRING(retval, "IWasSerialized");
    return 1;
}
