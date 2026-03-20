#include "handlers_api.h"
#include "remote_config.h"
#include <signal.h>
#include <php.h>

/* We need to do signal blocking for the remote config signaling to not interfere with some PHP functions.
 * See e.g. https://github.com/php/php-src/issues/16800
 * I don't know the full problem space, so I expect there might be functions missing here, and we need to eventually expand this list.
 */
static void dd_handle_signal(zif_handler original_function, INTERNAL_FUNCTION_PARAMETERS) {
    sigset_t x;
    sigemptyset(&x);
    sigaddset(&x, SIGVTALRM);
    sigprocmask(SIG_BLOCK, &x, NULL);

    original_function(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    sigprocmask(SIG_UNBLOCK, &x, NULL);
#ifndef __linux__
    // At least on linux unblocking causes immediate signal delivery.
    ddtrace_check_for_new_config_now();
#endif
}

#define BLOCKSIGFN(function) \
    static zif_handler dd_handle_signal_zif_##function; \
    static ZEND_FUNCTION(dd_handle_signal_##function) { \
        dd_handle_signal(dd_handle_signal_zif_##function, INTERNAL_FUNCTION_PARAM_PASSTHRU); \
    }

#define BLOCKSIGMETH(classname, method) \
    static zif_handler dd_handle_signal_zim_##classname##_##method; \
    static ZEND_METHOD(dd_handle_signal_##classname, method) { \
        dd_handle_signal(dd_handle_signal_zim_##classname##_##method, INTERNAL_FUNCTION_PARAM_PASSTHRU); \
    }

#define BLOCK(x) \
    x(ftp_alloc) \
    x(ftp_append) \
    x(ftp_cdup) \
    x(ftp_chdir) \
    x(ftp_chmod) \
    x(ftp_close) \
    x(ftp_connect) \
    x(ftp_delete) \
    x(ftp_exec) \
    x(ftp_fget) \
    x(ftp_fput) \
    x(ftp_get) \
    x(ftp_get_option) \
    x(ftp_login) \
    x(ftp_mdtm) \
    x(ftp_mkdir) \
    x(ftp_mlsd) \
    x(ftp_nb_continue) \
    x(ftp_nb_fget) \
    x(ftp_nb_fput) \
    x(ftp_nb_get) \
    x(ftp_nb_put) \
    x(ftp_nlist) \
    x(ftp_pasv) \
    x(ftp_put) \
    x(ftp_pwd) \
    x(ftp_quit) \
    x(ftp_raw) \
    x(ftp_rawlist) \
    x(ftp_rename) \
    x(ftp_rmdir) \
    x(ftp_site) \
    x(ftp_size) \
    x(ftp_ssl_connect) \
    x(ftp_systype) \
    x(mysqli_connect) \
    x(mysqli_real_connect) \
    x(mysql_connect) \
    x(pg_connect) \
    x(oci_connect) \
    x(odbc_connect) \
    x(ldap_connect) \
    x(sqlsrv_connect) \
    x(pcntl_sigwaitinfo) \
    x(sleep) \
    x(usleep) \
    x(time_nanosleep) \

#define BLOCKMETH(x) \
    x(PDO, connect) \
    x(mysqli, __construct) \
    x(mysqli, real_connect)

BLOCK(BLOCKSIGFN)

BLOCKMETH(BLOCKSIGMETH)

void ddtrace_signal_block_handlers_startup() {
#define BLOCKFNENTRY(function) { ZEND_STRL(#function), &dd_handle_signal_zif_##function, ZEND_FN(dd_handle_signal_##function) },
    datadog_php_zif_handler handlers[] = { BLOCK(BLOCKFNENTRY) };

    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        datadog_php_install_handler(handlers[i]);
    }

#define BLOCKMETHENTRY(classname, method) { ZEND_STRL(#classname), { ZEND_STRL(#method), &dd_handle_signal_zim_##classname##_##method, ZEND_MN(dd_handle_signal_##classname##_##method) } },
    datadog_php_zim_handler meth_handlers[] = { BLOCKMETH(BLOCKMETHENTRY) };

    size_t meth_handlers_len = sizeof meth_handlers / sizeof meth_handlers[0];
    for (size_t i = 0; i < meth_handlers_len; ++i) {
        datadog_php_install_method_handler(meth_handlers[i]);
    }
}
