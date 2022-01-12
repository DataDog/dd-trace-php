#include "../zai_sapi.h"

#include <Zend/zend_exceptions.h>
#include <main/php_main.h>
#include <main/php_variables.h>

#include "../zai_sapi_extension.h"
#include "../zai_sapi_functions.h"
#include "../zai_sapi_ini.h"
#include "../zai_sapi_io.h"

#define DEFAULT_INI        \
    "html_errors=0\n"      \
    "implicit_flush=1\n"   \
    "output_buffering=0\n" \
    "\0"

#define UNUSED(x) (void)(x)

static ssize_t ini_entries_len = -1;

static int zs_startup(sapi_module_struct *sapi_module) {
    return php_module_startup(sapi_module, &zai_sapi_extension, 1);
}

static int zs_deactivate(void) { return SUCCESS; }

static void zs_send_header(sapi_header_struct *sapi_header, void *server_context) {
    UNUSED(sapi_header);
    UNUSED(server_context);
}

static char *zs_read_cookies(void) { return NULL; }

void (*zai_sapi_register_custom_server_variables)(zval *track_vars_server_array);

static void zs_register_variables(zval *track_vars_array) {
    php_import_environment_variables(track_vars_array);
    if (zai_sapi_register_custom_server_variables) {
        zai_sapi_register_custom_server_variables(track_vars_array);
    }
}

static size_t zs_io_write_stdout(const char *str, size_t str_length) {
    size_t len = zai_sapi_io_write_stdout(str, str_length);
    if (len == 0) php_handle_aborted_connection();
    return len;
}

static void zs_io_log_message(const char *message, int syslog_type_int) {
    UNUSED(syslog_type_int);
    char buf[ZAI_SAPI_IO_ERROR_LOG_MAX_BUF_SIZE];
    size_t len = zai_sapi_io_format_error_log(message, buf, sizeof buf);
    /* We ignore the return because PHP does not care if this fails. */
    (void)zai_sapi_io_write_stderr(buf, len);
}

sapi_module_struct zai_module = {
    "zai",                     /* name */
    "Zend Abstract Interface", /* pretty name */

    zs_startup,                  /* startup */
    php_module_shutdown_wrapper, /* shutdown */

    NULL,          /* activate */
    zs_deactivate, /* deactivate */

    zs_io_write_stdout, /* unbuffered write */
    zai_sapi_io_flush,  /* flush */

    NULL, /* get uid */
    NULL, /* getenv */

    php_error, /* error handler */

    NULL,           /* header handler */
    NULL,           /* send headers handler */
    zs_send_header, /* send header handler */

    NULL,            /* read POST data */
    zs_read_cookies, /* read Cookies */

    zs_register_variables, /* register server variables */
    zs_io_log_message,     /* Log message */
    NULL,                  /* Get request time */
    NULL,                  /* Child terminate */

    NULL, /* php_ini_path_override   */
    NULL, /* default_post_reader     */
    NULL, /* treat_data              */
    NULL, /* executable_location     */
    0,    /* php_ini_ignore          */
    0,    /* php_ini_ignore_cwd      */
    NULL, /* get_fd                  */
    NULL, /* force_http_10           */
    NULL, /* get_target_uid          */
    NULL, /* get_target_gid          */
    NULL, /* input_filter            */
    NULL, /* ini_defaults            */
    0,    /* phpinfo_as_text;        */
    NULL, /* ini_entries;            */

    zai_sapi_functions, /* additional_functions */

    NULL /* input_filter_init */
};

bool zai_sapi_append_system_ini_entry(const char *key, const char *value) {
    ssize_t len = zai_sapi_ini_entries_realloc_append(&zai_module.ini_entries, (size_t)ini_entries_len, key, value);
    if (len <= ini_entries_len) {
        /* Play it safe and free if writing failed. */
        zai_sapi_ini_entries_free(&zai_module.ini_entries);
        return false;
    }
    ini_entries_len = len;
    return true;
}

#ifdef ZTS
static void zs_tsrm_startup(void) {
    php_tsrm_startup();
    ZEND_TSRMLS_CACHE_UPDATE();
}
#endif

bool zai_sapi_sinit(void) {
#ifdef ZTS
    zs_tsrm_startup();
#endif

    zend_signal_startup();

    /* Initialize the SAPI globals (memset to '0'), and set up reentrancy. */
    sapi_startup(&zai_module);

    /* Do not chdir to the script's directory (equivalent to running the CLI
     * SAPI with '-C').
     */
    SG(options) |= SAPI_OPTION_NO_CHDIR;

    /* Allocate the initial SAPI INI settings. Append new INI settings to this
     * with zai_sapi_append_system_ini_entry() before MINIT is run.
     */
    if ((ini_entries_len = zai_sapi_ini_entries_alloc(DEFAULT_INI, &zai_module.ini_entries)) == -1) return false;

    /* Don't load any INI files (equivalent to running the CLI SAPI with '-n').
     * This will prevent inadvertently loading any extensions that we did not
     * intend to. It also gives us a consistent clean slate of INI settings.
     */
    zai_module.php_ini_ignore = zai_sapi_php_ini_ignore() ? 1 : 0;

    /* Show phpinfo()/module info as plain text. */
    zai_module.phpinfo_as_text = 1;

    /* Reset the additional module global. */
    zai_sapi_reset_extension_global();

    /* Reset custom server variable registration callback */
    zai_sapi_register_custom_server_variables = NULL;

    return true;
}

void zai_sapi_sshutdown(void) {
    sapi_shutdown();
#ifdef ZTS
    tsrm_shutdown();
#endif
    zai_sapi_ini_entries_free(&zai_module.ini_entries);
}

bool zai_sapi_minit(void) {
    if (zai_module.startup(&zai_module) == FAILURE) {
        zai_sapi_sshutdown();
        return false;
    }
    return true;
}

void zai_sapi_mshutdown(void) { php_module_shutdown(); }

bool zai_sapi_rinit(void) {
    if (php_request_startup() == FAILURE) {
        return false;
    }

    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;

    php_register_variable("PHP_SELF", "-", NULL);

    return true;
}

void zai_sapi_rshutdown(void) { php_request_shutdown((void *)0); }

bool zai_sapi_spinup(void) { return zai_sapi_sinit() && zai_sapi_minit() && zai_sapi_rinit(); }

void zai_sapi_spindown(void) {
    zai_sapi_rshutdown();
    zai_sapi_mshutdown();
    zai_sapi_sshutdown();
}

bool zai_sapi_execute_script(const char *file) {
    zend_file_handle handle;
    zend_stream_init_filename(&handle, file);
    return zend_execute_scripts(ZEND_REQUIRE, NULL, 1, &handle) == SUCCESS;
}

bool zai_sapi_fake_frame_push(zend_execute_data *frame) {
    zend_function *func = zend_hash_str_find_ptr(EG(function_table), ZEND_STRL("zai\\noop"));
    if (func) {
        memset(frame, 0, sizeof(zend_execute_data));

        frame->func = func;
        frame->prev_execute_data = EG(current_execute_data);

        EG(current_execute_data) = frame;
        return true;
    }
    return false;
}

void zai_sapi_fake_frame_pop(zend_execute_data *frame) { EG(current_execute_data) = frame->prev_execute_data; }

bool zai_sapi_last_error_eq(int error_type, const char *msg) {
    if (PG(last_error_type) != error_type || PG(last_error_message) == NULL) return false;
    return strcmp(msg, ZSTR_VAL(PG(last_error_message))) == 0;
}

bool zai_sapi_last_error_is_empty(void) {
    return PG(last_error_type) == 0 && PG(last_error_lineno) == 0 && PG(last_error_message) == NULL &&
           PG(last_error_file) == NULL;
}

zend_class_entry *zai_sapi_throw_exception(const char *message) {
    zend_class_entry *ce = zend_exception_get_default();
    zend_throw_exception(ce, message, 0);
    return ce;
}

bool zai_sapi_unhandled_exception_eq(zend_class_entry *ce, const char *message) {
    if (!zai_sapi_unhandled_exception_exists()) return false;
    if (ce != EG(exception)->ce) return false;

    zval rv;
    zval *zmsg = zend_read_property_ex(ce, EG(exception), ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
    if (!zmsg && Z_TYPE_P(zmsg) != IS_STRING) return false;

    return strcmp(Z_STRVAL_P(zmsg), message) == 0;
}

bool zai_sapi_unhandled_exception_exists(void) { return EG(exception) != NULL; }

void zai_sapi_unhandled_exception_ignore(void) { zend_clear_exception(); }
