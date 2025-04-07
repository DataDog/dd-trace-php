#include "user_request.h"
#include <main/SAPI.h>
#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

#define NS "DDTrace\\UserRequest\\"

static struct {
    ddtrace_user_req_listeners **listeners;
    size_t size;
} reg_listeners;

DDTRACE_PUBLIC bool ddtrace_user_req_add_listeners(ddtrace_user_req_listeners *listeners)
{
    if (strcmp(sapi_module.name, "cli") != 0 && strcmp(sapi_module.name, "frankenphp") != 0) {
        return false;
    }

    reg_listeners.size += 1;
    reg_listeners.listeners = realloc(reg_listeners.listeners,
        sizeof(*reg_listeners.listeners) * reg_listeners.size);
    reg_listeners.listeners[reg_listeners.size - 1] = listeners;

    // bubble sort by .priority:
    for (size_t i = 0; i < reg_listeners.size; i++) {
        for (size_t j = i + 1; j < reg_listeners.size; j++) {
            if (reg_listeners.listeners[i]->priority > reg_listeners.listeners[j]->priority) {
                ddtrace_user_req_listeners *tmp = reg_listeners.listeners[i];
                reg_listeners.listeners[i] = reg_listeners.listeners[j];
                reg_listeners.listeners[j] = tmp;
            }
        }
    }

    return true;
}

PHP_FUNCTION(DDTrace_UserRequest_has_listeners);
PHP_FUNCTION(DDTrace_UserRequest_has_listeners)
{
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_FALSE;
    }

    RETURN_BOOL(reg_listeners.size > 0);
}

PHP_FUNCTION(DDTrace_UserRequest_notify_start);
PHP_FUNCTION(DDTrace_UserRequest_notify_start)
{
    zend_object *span;
    zend_array *array;
    zval *rbe_zv = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_OBJ_OF_CLASS_EX(span, ddtrace_ce_root_span_data, 0, 1)
        Z_PARAM_ARRAY_HT(array)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(rbe_zv)
    ZEND_PARSE_PARAMETERS_END();

    ddtrace_span_data *span_data = OBJ_SPANDATA(span);
    if (span_data->duration != 0) {
        php_error_docref(NULL, E_WARNING, "Span already finished");
        RETURN_NULL();
    }
    if (span_data->notify_user_req_end) {
        php_error_docref(NULL, E_WARNING, "Start of span already notified");
        RETURN_NULL();
    }

    zend_array *replacement_resp = NULL;
    for (size_t i = 0; i < reg_listeners.size; i++) {
        ddtrace_user_req_listeners *listener = reg_listeners.listeners[i];
        zend_array *repl = listener->start_user_req(listener, span, array, rbe_zv);
        if (repl != NULL && replacement_resp == NULL) {
            replacement_resp = repl;
        } else if (repl != NULL) {
            zend_array_release(repl);
        }
    }

    span_data->notify_user_req_end = true;

    if (replacement_resp != NULL) {
        RETURN_ARR(replacement_resp);
    } else {
        RETURN_NULL();
    }
}

PHP_FUNCTION(DDTrace_UserRequest_notify_commit);
PHP_FUNCTION(DDTrace_UserRequest_notify_commit)
{
    zend_object *span;
    zend_long status;
    zend_array *headers;
    zval *rbe_zv = NULL;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_OBJ_OF_CLASS_EX(span, ddtrace_ce_root_span_data, 0, 1)
        Z_PARAM_LONG(status)
        Z_PARAM_ARRAY_HT(headers)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(rbe_zv)
    ZEND_PARSE_PARAMETERS_END();

    if (rbe_zv != NULL) {
        ZVAL_DEREF(rbe_zv);
        if (Z_TYPE_P(rbe_zv) == IS_STRING) {
            // ok
        } else if (Z_TYPE_P(rbe_zv) == IS_RESOURCE) {
            zend_resource *res = Z_RES_P(rbe_zv);
            if (res->type != php_file_le_stream()) {
                zend_type_error("Expected resource of type stream or a string; but the resource is not a stream");
                return;
            }
        } else {
            zend_type_error("Expected resource of type stream or a string");
            return;
        }
    }

    if (status < 100 || status > 599) {
        zend_value_error("Status code must be between 100 and 599");
        return;
    }

    bool free_headers = false;

    zend_array *replacement_resp = NULL;
    for (size_t i = 0; i < reg_listeners.size; i++) {
        ddtrace_user_req_listeners *listener = reg_listeners.listeners[i];
        zend_array *repl = listener->response_committed(listener, span, (int)status, headers, rbe_zv);
        if (repl) {
            {
                zval *new_status_zv = zend_hash_str_find(repl, ZEND_STRL("status"));
                if (Z_TYPE_P(new_status_zv) == IS_LONG) {
                    status = Z_LVAL_P(new_status_zv);
                } else if (Z_TYPE_P(new_status_zv) == IS_STRING) {
                    int new_status = atoi(Z_STRVAL_P(new_status_zv));
                    if (new_status != 0) {
                        status = new_status;
                    }
                } else {
                    status = 200;
                }
            }

            {
                zval *new_headers_zv = zend_hash_str_find(repl, ZEND_STRL("headers"));
                if (free_headers) {
                    zend_array_release(headers);
                }
                if (new_headers_zv && Z_TYPE_P(new_headers_zv) == IS_ARRAY) {
                    headers = Z_ARR_P(new_headers_zv);
                    free_headers = false;
                } else {
                    // in PHP 8 could use zend_empty_array here and avoid the free_headers flag
                    headers = zend_new_array(0);
                    free_headers = true;
                }
            }

            {
                zval *new_body_zv = zend_hash_str_find(repl, ZEND_STRL("body"));
                if (new_body_zv) {
                    rbe_zv = new_body_zv;
                } else {
                    rbe_zv = NULL;
                }
            }

            if (replacement_resp) {
                zend_array_release(replacement_resp);
            }
            replacement_resp = repl;
        }
    }

    if (free_headers) {
        zend_array_release(headers);
    }

    if (replacement_resp != NULL) {
        RETURN_ARR(replacement_resp);
    } else {
        RETURN_NULL();
    }
}

void ddtrace_user_req_notify_finish(ddtrace_span_data *span)
{
    for (size_t i = 0; i < reg_listeners.size; i++) {
        ddtrace_user_req_listeners *listener = reg_listeners.listeners[i];
        listener->finish_user_req(listener, &span->std);
    }
}

PHP_FUNCTION(DDTrace_UserRequest_set_blocking_function);
PHP_FUNCTION(DDTrace_UserRequest_set_blocking_function)
{
    UNUSED(return_value);
    zend_object *span;
    zval *callable;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJ_OF_CLASS_EX(span, ddtrace_ce_root_span_data, 0, 1)
        Z_PARAM_ZVAL(callable)
    ZEND_PARSE_PARAMETERS_END();

    for (size_t i = 0; i < reg_listeners.size; i++) {
        ddtrace_user_req_listeners *listener = reg_listeners.listeners[i];
        if (listener->set_blocking_function) {
            listener->set_blocking_function(listener, span, callable);
        }
    }
}

void ddtrace_user_req_shutdown()
{
    for (size_t i = 0; i < reg_listeners.size; i++) {
        ddtrace_user_req_listeners *listener = reg_listeners.listeners[i];
        if (listener->delete) {
            listener->delete (listener);
        }
    }
    free(reg_listeners.listeners);
    reg_listeners.size = 0;
    reg_listeners.listeners = NULL;
}
