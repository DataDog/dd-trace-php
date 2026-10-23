#include "handlers_api.h"
#include "remote_config.h"
#include <signal.h>
#include <php.h>

#ifdef __linux__
# ifdef HAVE_VALGRIND
#  include <valgrind/valgrind.h>
# else
#  define RUNNING_ON_VALGRIND 0
# endif
// On Linux, unblocking SIGVTALRM causes immediate delivery if it was pending.
// We only need to poll explicitly when running under valgrind, which intercepts
// SIGVTALRM for its own timing and makes signal delivery unreliable.
# define REMOTE_CONFIG_NEEDS_POLLING_AFTER_SIGNAL RUNNING_ON_VALGRIND
#else
// On non-Linux platforms, SIGVTALRM delivery after unblock is not guaranteed,
// so always poll explicitly.
# define REMOTE_CONFIG_NEEDS_POLLING_AFTER_SIGNAL 1
#endif

static bool dd_block_signal(void) {
    sigset_t x, old;
    sigemptyset(&x);
    sigaddset(&x, SIGVTALRM);
    sigprocmask(SIG_BLOCK, &x, &old);
    // Nested blocking (e.g. a stream notification callback calling a blocked function) must not unblock early
    return !sigismember(&old, SIGVTALRM);
}

static void dd_unblock_signal(bool was_blocked) {
    if (!was_blocked) {
        return;
    }

    sigset_t x;
    sigemptyset(&x);
    sigaddset(&x, SIGVTALRM);
    sigprocmask(SIG_UNBLOCK, &x, NULL);
    // ensures no double-processing when the signal was delivered normally.
    if (REMOTE_CONFIG_NEEDS_POLLING_AFTER_SIGNAL) {
        datadog_check_for_new_config_now();
    }
}

/* We need to do signal blocking for the remote config signaling to not interfere with some PHP functions.
 * See e.g. https://github.com/php/php-src/issues/16800
 * I don't know the full problem space, so I expect there might be functions missing here, and we need to eventually expand this list.
 */
static void dd_handle_signal(zif_handler original_function, INTERNAL_FUNCTION_PARAMETERS) {
    bool blocked = dd_block_signal();

    original_function(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    dd_unblock_signal(blocked);
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

// Beware of uppercase names. class/function tables are lowercase keys!
#define BLOCKMETH(x) \
    x(pdo, __construct) \
    x(pdo, connect) \
    x(mysqli, __construct) \
    x(mysqli, connect) \
    x(mysqli, real_connect)

#if PHP_VERSION_ID < 80500
/* Before php-src commit 69765d92 (PHP 8.2.27, 8.3.15, 8.4.2) php_network_connect_socket() did not retry the poll() on EINTR,
 * failing the connect instead. Everything ending up in php_network_connect_socket() needs to be guarded on these versions:
 * the stream transports (tcp, udp, unix, ssl, tls, ...) via php_stream_xport_create() and ext/ftp (already guarded above).
 * Stream wrappers connecting via transports (http, ftp) are guarded at the wrapper level, see dd_signal_stream_wrapper.
 */
#define BLOCK_CONNECT(x) \
    x(fsockopen) \
    x(pfsockopen) \
    x(stream_socket_client)

#define BLOCKMETH_CONNECT(x) \
    x(soapclient, __dorequest) \
    x(redis, connect) \
    x(redis, pconnect) \
    x(redis, open) \
    x(redis, popen) \
    x(rediscluster, __construct) \
    x(redisarray, __construct)

BLOCK(BLOCKSIGFN)
BLOCK_CONNECT(BLOCKSIGFN)

BLOCKMETH(BLOCKSIGMETH)
BLOCKMETH_CONNECT(BLOCKSIGMETH)

static bool dd_has_unguarded_stream_connect(void) {
#if PHP_VERSION_ID < 80200
    return true;
#else
#if PHP_VERSION_ID < 80300
#define STREAM_CONNECT_EINTR_FIXED_PATCH_VERSION 27
#elif PHP_VERSION_ID < 80400
#define STREAM_CONNECT_EINTR_FIXED_PATCH_VERSION 15
#else
#define STREAM_CONNECT_EINTR_FIXED_PATCH_VERSION 2
#endif
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    return patch_version < STREAM_CONNECT_EINTR_FIXED_PATCH_VERSION;
#endif
}

typedef struct {
    php_stream_wrapper wrapper; // must be first: the ops receive a pointer to it
    php_stream_wrapper_ops wops;
    const php_stream_wrapper *orig_wrapper;
} dd_signal_stream_wrapper;

// http and ftp, each also registered as https and ftps
static dd_signal_stream_wrapper dd_signal_stream_wrappers[4];

#define DD_ORIG_WOPS(wrapper) (((dd_signal_stream_wrapper *)(wrapper))->orig_wrapper->wops)

static php_stream *dd_signal_stream_opener(php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    bool blocked = dd_block_signal();
    php_stream *stream = DD_ORIG_WOPS(wrapper)->stream_opener(wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC);
    dd_unblock_signal(blocked);
    return stream;
}

static int dd_signal_url_stat(php_stream_wrapper *wrapper, const char *url, int flags, php_stream_statbuf *ssb, php_stream_context *context) {
    bool blocked = dd_block_signal();
    int ret = DD_ORIG_WOPS(wrapper)->url_stat(wrapper, url, flags, ssb, context);
    dd_unblock_signal(blocked);
    return ret;
}

static php_stream *dd_signal_dir_opener(php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    bool blocked = dd_block_signal();
    php_stream *stream = DD_ORIG_WOPS(wrapper)->dir_opener(wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC);
    dd_unblock_signal(blocked);
    return stream;
}

static int dd_signal_unlink(php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) {
    bool blocked = dd_block_signal();
    int ret = DD_ORIG_WOPS(wrapper)->unlink(wrapper, url, options, context);
    dd_unblock_signal(blocked);
    return ret;
}

static int dd_signal_rename(php_stream_wrapper *wrapper, const char *url_from, const char *url_to, int options, php_stream_context *context) {
    bool blocked = dd_block_signal();
    int ret = DD_ORIG_WOPS(wrapper)->rename(wrapper, url_from, url_to, options, context);
    dd_unblock_signal(blocked);
    return ret;
}

static int dd_signal_stream_mkdir(php_stream_wrapper *wrapper, const char *url, int mode, int options, php_stream_context *context) {
    bool blocked = dd_block_signal();
    int ret = DD_ORIG_WOPS(wrapper)->stream_mkdir(wrapper, url, mode, options, context);
    dd_unblock_signal(blocked);
    return ret;
}

static int dd_signal_stream_rmdir(php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) {
    bool blocked = dd_block_signal();
    int ret = DD_ORIG_WOPS(wrapper)->stream_rmdir(wrapper, url, options, context);
    dd_unblock_signal(blocked);
    return ret;
}

// Wraps the operations of the URL wrappers which may connect sockets: these are used by any function accepting a filename
static void dd_signal_block_stream_wrapper(const char *protocol, size_t protocol_len) {
    HashTable *wrappers = php_stream_get_url_stream_wrappers_hash_global();
    const php_stream_wrapper *orig = zend_hash_str_find_ptr(wrappers, protocol, protocol_len);
    if (!orig || !orig->wops) {
        return;
    }

    dd_signal_stream_wrapper *slot = NULL;
    for (size_t i = 0; i < sizeof dd_signal_stream_wrappers / sizeof dd_signal_stream_wrappers[0]; ++i) {
        dd_signal_stream_wrapper *cur = &dd_signal_stream_wrappers[i];
        if (orig == &cur->wrapper) {
            return;
        }
        if (cur->orig_wrapper == orig || !cur->orig_wrapper) {
            slot = cur;
            break;
        }
    }
    if (!slot) {
        return;
    }

    if (!slot->orig_wrapper) {
        slot->orig_wrapper = orig;
        slot->wrapper = *orig;
        slot->wops = *orig->wops;
        slot->wrapper.wops = &slot->wops;
#define DD_WRAP_WOP(op) if (slot->wops.op) { slot->wops.op = dd_signal_##op; }
        DD_WRAP_WOP(stream_opener)
        DD_WRAP_WOP(url_stat)
        DD_WRAP_WOP(dir_opener)
        DD_WRAP_WOP(unlink)
        DD_WRAP_WOP(rename)
        DD_WRAP_WOP(stream_mkdir)
        DD_WRAP_WOP(stream_rmdir)
#undef DD_WRAP_WOP
    }

    zend_hash_str_update_ptr(wrappers, protocol, protocol_len, &slot->wrapper);
}
#endif

#if PHP_VERSION_ID >= 80400
// Internal subclasses hold a copy of the parent method made at their registration, install there too
#define BLOCKMETH_INHERITED(x) \
    x("pdo\\mysql", pdo, __construct) \
    x("pdo\\mysql", pdo, connect) \
    x("pdo\\pgsql", pdo, __construct) \
    x("pdo\\pgsql", pdo, connect) \
    x("pdo\\sqlite", pdo, __construct) \
    x("pdo\\sqlite", pdo, connect) \
    x("pdo\\odbc", pdo, __construct) \
    x("pdo\\odbc", pdo, connect) \
    x("pdo\\firebird", pdo, __construct) \
    x("pdo\\firebird", pdo, connect) \
    x("pdo\\dblib", pdo, __construct) \
    x("pdo\\dblib", pdo, connect)

static void dd_install_inherited_signal_method_handler(const char *class_name, size_t class_name_len, datadog_php_zif_handler handler) {
    zend_class_entry *ce = zend_hash_str_find_ptr(CG(class_table), class_name, class_name_len);
    if (!ce || !*handler.old_handler) {
        return;
    }

    zend_function *func = zend_hash_str_find_ptr(&ce->function_table, handler.name, handler.name_len);
    if (func && func->type == ZEND_INTERNAL_FUNCTION && func->internal_function.handler == *handler.old_handler) {
        func->internal_function.handler = handler.new_handler;
    }
}
#endif

void datadog_signal_block_handlers_startup() {
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

#if PHP_VERSION_ID >= 80400
#define BLOCKMETHINHERITEDENTRY(classname, parent, method) dd_install_inherited_signal_method_handler(ZEND_STRL(classname), (datadog_php_zif_handler){ ZEND_STRL(#method), &dd_handle_signal_zim_##parent##_##method, ZEND_MN(dd_handle_signal_##parent##_##method) });
    BLOCKMETH_INHERITED(BLOCKMETHINHERITEDENTRY)
#endif

#if PHP_VERSION_ID < 80500
    if (dd_has_unguarded_stream_connect()) {
        datadog_php_zif_handler connect_handlers[] = { BLOCK_CONNECT(BLOCKFNENTRY) };

        size_t connect_handlers_len = sizeof connect_handlers / sizeof connect_handlers[0];
        for (size_t i = 0; i < connect_handlers_len; ++i) {
            datadog_php_install_handler(connect_handlers[i]);
        }

        datadog_php_zim_handler connect_meth_handlers[] = { BLOCKMETH_CONNECT(BLOCKMETHENTRY) };

        size_t connect_meth_handlers_len = sizeof connect_meth_handlers / sizeof connect_meth_handlers[0];
        for (size_t i = 0; i < connect_meth_handlers_len; ++i) {
            datadog_php_install_method_handler(connect_meth_handlers[i]);
        }

        dd_signal_block_stream_wrapper(ZEND_STRL("http"));
        dd_signal_block_stream_wrapper(ZEND_STRL("https"));
        dd_signal_block_stream_wrapper(ZEND_STRL("ftp"));
        dd_signal_block_stream_wrapper(ZEND_STRL("ftps"));
    }
#endif
}
