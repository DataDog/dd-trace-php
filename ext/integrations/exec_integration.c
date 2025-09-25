#include "exec_integration.h"

#include <stdio.h>
#ifndef _WIN32
#include <sys/wait.h>
#endif

#include <ext/standard/file.h>
#include <ext/standard/proc_open.h>

#include "../compatibility.h"
#include "../ddtrace.h"
#include "../span.h"
#include "exec_integration_arginfo.h"

#if PHP_VERSION_ID < 80000
typedef struct php_process_handle php_process_handle;
#endif

/* proc_open / proc_close handling */
typedef struct _dd_proc_span {
    zend_object *span;
    php_process_id_t child;
#ifdef _WIN32
    HANDLE childHandle;
#endif
} dd_proc_span;
static int le_proc;
static int le_proc_span;

/* popen stream handler close interception */

static int dd_php_stdiop_close_wrapper(php_stream *stream, int close_handle);
static void dd_waitpid(ddtrace_span_data *, dd_proc_span *);

ZEND_TLS HashTable *tracked_streams;  // php_stream => span
static zend_string *cmd_exit_code_zstr;
static zend_string *error_message_zstr;
static zend_string *has_signalled_zstr;
static zend_string *pclose_minus_one_zstr;
static int (*orig_php_stream_stdio_ops_close)(php_stream *stream, int close_handle);

static inline bool in_request() { return tracked_streams != NULL; }

static void dd_exec_init_track_streams() {
    ALLOC_HASHTABLE(tracked_streams);
    zend_hash_init(tracked_streams, 8, NULL, ZVAL_PTR_DTOR, 0);
}
static void dd_exec_destroy_tracked_streams() {
    if (!tracked_streams) {
        return;
    }
    zend_hash_destroy(tracked_streams);
    FREE_HASHTABLE(tracked_streams);
    tracked_streams = NULL;
}

PHP_FUNCTION(DDTrace_Integrations_Exec_register_stream) {
    php_stream *stream;
    zval *zstream;
    zend_object *span;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_RESOURCE(zstream)
    Z_PARAM_OBJ(span)
    ZEND_PARSE_PARAMETERS_END();

    php_stream_from_res(stream, Z_RES_P(zstream));
    if (!stream) {
        RETURN_FALSE;
    }

    zval zspan;
    ZVAL_OBJ(&zspan, span);
    zend_hash_str_add(tracked_streams, (const char *)&stream, sizeof stream, &zspan);
    GC_ADDREF(span);

    RETURN_TRUE;
}

static int dd_php_stdiop_close_wrapper(php_stream *stream, int close_handle) {
    int ret = orig_php_stream_stdio_ops_close(stream, close_handle);

    if (!in_request()) {
        return ret;
    }

    zval *span_data_zv = zend_hash_str_find(tracked_streams, (const char *)&stream, sizeof stream);
    if (!span_data_zv) {
        return ret;
    }

    ddtrace_span_data *span_data = OBJ_SPANDATA(Z_OBJ_P(span_data_zv));

    zend_array *meta = ddtrace_property_array(&span_data->property_meta);
    if (ret == -1) {
        zval zv;
        ZVAL_INTERNED_STR(&zv, pclose_minus_one_zstr);
        zend_hash_update(meta, error_message_zstr, &zv);
    } else {
        zval zexit;
        ZVAL_LONG(&zexit, ret);
        zend_hash_update(meta, cmd_exit_code_zstr, &zexit);
    }

    dd_trace_stop_span_time(span_data);
    ddtrace_close_span_restore_stack(span_data);

    zend_hash_str_del(tracked_streams, (const char *)&stream, sizeof stream);

    return ret;
}

static void dd_proc_wrapper_rsrc_dtor(zend_resource *rsrc) {
    // this is called from the beginning of proc_open_rsrc_dtor,
    // before the process is possibly reaped

    dd_proc_span *proc_span = (dd_proc_span *)rsrc->ptr;

    ddtrace_span_data *span_data = OBJ_SPANDATA(proc_span->span);

    if (span_data->duration == 0) {
        dd_waitpid(span_data, proc_span);

        dd_trace_stop_span_time(span_data);
        ddtrace_close_span_restore_stack(span_data);
    }  // else we already finished the span in proc_get_status

    zend_object_release(proc_span->span);
    efree(proc_span);
}

static void dd_waitpid(ddtrace_span_data *span_data, dd_proc_span *proc) {
    if (span_data->duration) {
        // already closed
        return;
    }
    zend_array *meta = ddtrace_property_array(&span_data->property_meta);

    int wstatus;
    bool exited = false;

    // if FG(pclose_wait) is true, we're called from proc_close,
    // which will wait for the process to exit. We reproduce that behavior
#ifdef _WIN32
    if (FG(pclose_wait)) {
        WaitForSingleObject(proc->childHandle, INFINITE);
    }
    GetExitCodeProcess(proc->childHandle, &wstatus);
    if (wstatus != STILL_ACTIVE) {
        exited = true;
    }
#else
    int opts = FG(pclose_wait) ? 0 : WNOHANG | WUNTRACED;

    int pid_res = -1;
    int pid = proc->child;
    while ((pid_res = waitpid(pid, &wstatus, opts)) == -1 && errno == EINTR) {
    }

    if (pid_res != pid) {
        return;  // 0 was returned (no changed status) or some error
                 // Probably the process is no more/not a child
    }

    if (WIFEXITED(wstatus)) {
        exited = true;
        wstatus = WEXITSTATUS(wstatus);
    } else if (WIFSIGNALED(wstatus)) {
        // wstatus is not modified!
        // note that normal exit code 9 and signal 9 will both result in
        // the value 9 for wstatus (unlike e.g. the shell, which would
        // add 128 to the signal number). This may be strange, but
        // that's how PHP does it in the return value of proc_open
        zval has_signalled_zv;
        ZVAL_INTERNED_STR(&has_signalled_zv, has_signalled_zstr);
        zend_hash_update(meta, error_message_zstr, &has_signalled_zv);
        exited = true;
    }  // else !FG(pclose_wait) and it hasn't finished
#endif

    if (exited) {
        zval zexit;
        ZVAL_LONG(&zexit, wstatus);

        // set tag 'cmd.exit_code'
        zend_hash_update(meta, cmd_exit_code_zstr, &zexit);
    }
}

PHP_FUNCTION(DDTrace_Integrations_Exec_proc_assoc_span) {
    zval *zres;
    zend_object *span;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_RESOURCE(zres)
    Z_PARAM_OBJ(span)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_RES_TYPE_P(zres) != le_proc) {
        RETURN_FALSE;
    }

    php_process_handle *proc_h = Z_RES_P(zres)->ptr;

    dd_proc_span *proc_span = emalloc(sizeof *proc_span);
    proc_span->span = span;
    GC_ADDREF(span);
    proc_span->child = proc_h->child;
#ifdef _WIN32
    proc_span->childHandle = proc_h->childHandle;
#endif

    proc_h->npipes += 1;
    proc_h->pipes = safe_erealloc(proc_h->pipes, proc_h->npipes, sizeof *proc_h->pipes, 0);
    zend_resource *proc_span_res = zend_register_resource(proc_span, le_proc_span);
    proc_h->pipes[proc_h->npipes - 1] = proc_span_res;

    RETURN_TRUE;
}

PHP_FUNCTION(DDTrace_Integrations_Exec_proc_get_span) {
    zval *zres;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_RESOURCE(zres)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_RES_TYPE_P(zres) != le_proc) {
        RETURN_NULL();
    }

    php_process_handle *proc_h = Z_RES_P(zres)->ptr;
    if (proc_h->npipes == 0) {
        RETURN_NULL();
    }

    zend_resource *span_res = proc_h->pipes[proc_h->npipes - 1];
    if (span_res->type != le_proc_span || !span_res->ptr) {
        RETURN_NULL();
    }

    dd_proc_span *span_h = span_res->ptr;
    RETURN_OBJ_COPY(span_h->span);
}

// used in testing only
PHP_FUNCTION(DDTrace_Integrations_Exec_proc_get_pid) {
    zval *zres;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_RESOURCE(zres)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_RES_TYPE_P(zres) != le_proc) {
        RETURN_NULL();
    }

    php_process_handle *proc_h = Z_RES_P(zres)->ptr;
    RETURN_LONG((long)proc_h->child);
}
PHP_FUNCTION(DDTrace_Integrations_Exec_test_rshutdown) {
    if (zend_parse_parameters_none() != SUCCESS) {
        return;
    }

    ddtrace_exec_handlers_rshutdown();
    dd_exec_init_track_streams();
    RETURN_TRUE;
}

void ddtrace_exec_handlers_startup() {
    // popen
    orig_php_stream_stdio_ops_close = php_stream_stdio_ops.close;
    php_stream_stdio_ops.close = dd_php_stdiop_close_wrapper;

    zend_register_functions(NULL, ext_functions, NULL, MODULE_PERSISTENT);
    cmd_exit_code_zstr = zend_string_init_interned(ZEND_STRL("cmd.exit_code"), 1);
    error_message_zstr = zend_string_init_interned(ZEND_STRL("error.message"), 1);
    has_signalled_zstr = zend_string_init_interned(ZEND_STRL("The process was terminated by a signal"), 1);
    pclose_minus_one_zstr = zend_string_init_interned(ZEND_STRL("Closing popen() stream returned -1"), 1);

    // proc_open
    le_proc = zend_fetch_list_dtor_id("process");
    // we don't have the module number, but it's only relevant for persistent resources anyway
    le_proc_span = zend_register_list_destructors_ex(dd_proc_wrapper_rsrc_dtor, NULL, "process_wrapper", -1);
}

void ddtrace_exec_handlers_shutdown() {
    if (orig_php_stream_stdio_ops_close) {
        php_stream_stdio_ops.close = orig_php_stream_stdio_ops_close;
        orig_php_stream_stdio_ops_close = NULL;
    }
}

void ddtrace_exec_handlers_rinit() {
    // also called when ddtrace is reenabled mid-request.
    // OTOH ddtrace_exec_handlers_rshutdown is not called when ddtrace is
    // disabled because it needs to be called earlier on upon the real rshutodown

    if (tracked_streams) {
        dd_exec_destroy_tracked_streams();
    }

    dd_exec_init_track_streams();
}

void ddtrace_exec_handlers_rshutdown() {
    if (tracked_streams) {
        zend_ulong h;
        zend_string *key;
        zval *val;
        ZEND_HASH_REVERSE_FOREACH_KEY_VAL(tracked_streams, h, key, val) {
            (void)h;
            (void)val;
            php_stream *stream;
            memcpy(&stream, ZSTR_VAL(key), sizeof stream);
            // manually close the tracked stream on rshutdown in case they
            // lived till the end of the request so we can finish the span
            zend_list_close(stream->res);
        }
        ZEND_HASH_FOREACH_END();

        dd_exec_destroy_tracked_streams();
    }

    {
        zend_ulong h;
        zend_resource *rsrc;
        // iterate EG(regular_list) to destroy dd_proc_span resources
        // while we are still in the request
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&EG(regular_list), h, rsrc) {
            (void)h;
            if (rsrc->type == le_proc_span) {
                zend_list_close(rsrc);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
}
