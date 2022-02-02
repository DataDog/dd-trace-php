#include <include/sapi.h>

#include <main/php_main.h>
#include <main/php_variables.h>

#include <private/error.h>
#include <private/extension.h>
#include <private/frame.h>
#include <private/ini.h>
#include <private/io.h>

// clang-format off
#define TEA_SAPI_HAS_ZEND_SIGNALS                          \
    (PHP_VERSION_ID >= 80000) ||                           \
    (PHP_VERSION_ID >= 70125 && PHP_VERSION_ID < 70200) || \
    (PHP_VERSION_ID >= 70214 && PHP_VERSION_ID < 70300) || \
    (PHP_VERSION_ID >= 70301)
// clang-format on

#define TEA_SAPI_DEFAULT_INI \
    "date.timezone=UTC\n"    \
    "html_errors=0\n"        \
    "implicit_flush=1\n"     \
    "output_buffering=0\n"   \
    "\0"

#define UNUSED(x) (void)(x)

static ssize_t ini_entries_len = -1;

void (*tea_sapi_register_custom_server_variables)(zval *track_vars_server_array TEA_TSRMLS_DC);

static int ts_startup(sapi_module_struct *sapi_module) {
    return php_module_startup(sapi_module, tea_extension_module(), 1);
}

static int ts_deactivate(TEA_TSRMLS_D) {
#ifdef ZTS
#if PHP_VERSION_ID < 70000
    UNUSED(TEA_TSRMLS_C);
#endif
#endif
    return SUCCESS;
}

static void ts_send_header(sapi_header_struct *sapi_header, void *server_context TEA_TSRMLS_DC) {
    UNUSED(sapi_header);
    UNUSED(server_context);
#ifdef ZTS
#if PHP_VERSION_ID < 70000
    UNUSED(TEA_TSRMLS_C);
#endif
#endif
}

static char *ts_read_cookies(TEA_TSRMLS_D) {
#ifdef ZTS
#if PHP_VERSION_ID < 70000
    UNUSED(TEA_TSRMLS_C);
#endif
#endif
    return NULL;
}

static void ts_register_variables(zval *track_vars_array TEA_TSRMLS_DC) {
    php_import_environment_variables(track_vars_array TEA_TSRMLS_CC);
    if (tea_sapi_register_custom_server_variables) {
        tea_sapi_register_custom_server_variables(track_vars_array TEA_TSRMLS_CC);
    }
}

static inline void ts_flush_noop(void *server_context) { UNUSED(server_context); }

#if PHP_VERSION_ID >= 70000
static size_t ts_write_stdout(const char *str, size_t str_length) {
    size_t len = tea_io_write_stdout(str, str_length);
    if (len == 0) {
        php_handle_aborted_connection();
    }
    return len;
}
#else
static int ts_write_stdout(const char *str, unsigned int str_length TEA_TSRMLS_DC) {
#ifdef ZTS
#if PHP_VERSION_ID < 70000
    UNUSED(TEA_TSRMLS_C);
#endif
#endif
    size_t len = tea_io_write_stdout(str, (size_t)str_length);
    if (len == 0) {
        php_handle_aborted_connection();
    }
    return (int)len;
}
#endif

#if PHP_VERSION_ID >= 80000
static void ts_log_message(const char *message, int syslog_type_int) {
#elif PHP_VERSION_ID >= 70100
static void ts_log_message(char *message, int syslog_type_int) {
#elif PHP_VERSION_ID >= 70000
static void ts_log_message(char *message) {
#else
static void ts_log_message(char *message TEA_TSRMLS_DC) {
#endif
    char buf[TEA_IO_ERROR_LOG_MAX_BUF_SIZE];
    size_t len = tea_io_format_error_log(message, buf, sizeof buf);
    /* We ignore the return because PHP does not care if this fails. */
    (void)tea_io_write_stderr(buf, len);
}

sapi_module_struct tea_sapi_module = {
    "tea",      /* name */
    "TEA SAPI", /* pretty name */

    ts_startup,                  /* startup */
    php_module_shutdown_wrapper, /* shutdown */

    NULL,          /* activate */
    ts_deactivate, /* deactivate */

    ts_write_stdout, /* unbuffered write */
    ts_flush_noop,   /* flush */

    NULL, /* get uid */
    NULL, /* getenv */

    php_error, /* error handler */

    NULL,           /* header handler */
    NULL,           /* send headers handler */
    ts_send_header, /* send header handler */

    NULL,            /* read POST data */
    ts_read_cookies, /* read Cookies */

    ts_register_variables, /* register server variables */
    ts_log_message,        /* Log message */
    NULL,                  /* Get request time */
    NULL,                  /* Child terminate */

    NULL, /* php_ini_path_override   */
#if PHP_VERSION_ID < 70100
    NULL, /* block_interruptions     */
    NULL, /* unblock_interruptions   */
#endif
    NULL,                   /* default_post_reader     */
    php_default_treat_data, /* treat_data */
    NULL,                   /* executable_location     */
    0,                      /* php_ini_ignore          */
    0,                      /* php_ini_ignore_cwd      */
    NULL,                   /* get_fd                  */
    NULL,                   /* force_http_10           */
    NULL,                   /* get_target_uid          */
    NULL,                   /* get_target_gid          */
    NULL,                   /* input_filter            */
    NULL,                   /* ini_defaults            */
    0,                      /* phpinfo_as_text         */
    NULL,                   /* ini_entries             */
    NULL,                   /* additional_functions    */
    NULL                    /* input_filter_init       */
};

bool tea_sapi_append_system_ini_entry(const char *key, const char *value) {
    ssize_t len = tea_ini_realloc_append(&tea_sapi_module.ini_entries, (size_t)ini_entries_len, key, value);
    if (len <= ini_entries_len) {
        /* Play it safe and free if writing failed. */
        tea_ini_free(&tea_sapi_module.ini_entries);
        return false;
    }
    ini_entries_len = len;
    return true;
}

#ifdef ZTS
static inline void ts_tsrm_startup(void) {
#if PHP_VERSION_ID >= 80000
    php_tsrm_startup();
    ZEND_TSRMLS_CACHE_UPDATE();
#elif PHP_VERSION_ID >= 70000
#if PHP_VERSION_ID >= 70400
    php_tsrm_startup();
#else
    tsrm_startup(1, 1, 0, NULL);
    (void)ts_resource(0);
#endif
    ZEND_TSRMLS_CACHE_UPDATE();
#else
    tsrm_startup(1, 1, 0, NULL);
    (void)ts_resource(0);
#endif
}
#endif

bool tea_sapi_sinit(void) {
#ifdef ZTS
    ts_tsrm_startup();
    TEA_TSRMLS_FETCH();
#endif

#if TEA_SAPI_HAS_ZEND_SIGNALS
    /* Due to php-src bug #71041, 'zend_signal_startup' was not exported to
     * shared libs with 'ZEND_API' until PHP 7.1.25, 7.2.14, and 7.3.1+.
     *
     * https://bugs.php.net/bug.php?id=71041
     * https://github.com/php/php-src/commit/11ddf76
     */
    zend_signal_startup();
#endif

    /* Initialize the SAPI globals (memset to '0'), and set up reentrancy. */
    sapi_startup(&tea_sapi_module);

    /* Do not chdir to the script's directory (equivalent to running the CLI
     * SAPI with '-C').
     */
    SG(options) |= SAPI_OPTION_NO_CHDIR;

    /* Allocate the initial SAPI INI settings. Append new INI settings to this
     * with tea_sapi_append_system_ini_entry() before MINIT is run.
     */
    if ((ini_entries_len = tea_ini_alloc(TEA_SAPI_DEFAULT_INI, &tea_sapi_module.ini_entries)) == -1) return false;

    /* Don't load any INI files (equivalent to running the CLI SAPI with '-n').
     * This will prevent inadvertently loading any extensions that we did not
     * intend to. It also gives us a consistent clean slate of INI settings.
     */
    tea_sapi_module.php_ini_ignore = tea_ini_ignore() ? 1 : 0;

    /* Show phpinfo()/module info as plain text. */
    tea_sapi_module.phpinfo_as_text = 1;

    /* Reset custom server variable registration callback */
    tea_sapi_register_custom_server_variables = NULL;

    /* Reset extension state */
    tea_extension_sinit();

    /* Reset frame state */
    tea_frame_sinit();

    /* Reset error state */
    tea_error_sinit();

    return true;
}

void tea_sapi_sshutdown(void) {
    sapi_shutdown();
#ifdef ZTS
    tsrm_shutdown();
#endif
    tea_ini_free(&tea_sapi_module.ini_entries);
}

bool tea_sapi_minit(void) {
    if (tea_sapi_module.startup(&tea_sapi_module) == FAILURE) {
        tea_sapi_sshutdown();
        return false;
    }
    return true;
}

void tea_sapi_mshutdown(void) {
    TEA_TSRMLS_FETCH();
    php_module_shutdown(TEA_TSRMLS_C);
}

bool tea_sapi_rinit(void) {
    TEA_TSRMLS_FETCH();
    if (php_request_startup(TEA_TSRMLS_C) == FAILURE) {
        return false;
    }

    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;

    php_register_variable("PHP_SELF", "-", NULL TEA_TSRMLS_CC);

    return true;
}

void tea_sapi_rshutdown(void) { php_request_shutdown((void *)0); }

bool tea_sapi_spinup(void) { return tea_sapi_sinit() && tea_sapi_minit() && tea_sapi_rinit(); }

void tea_sapi_spindown(void) {
    tea_sapi_rshutdown();
    tea_sapi_mshutdown();
    tea_sapi_sshutdown();
}
