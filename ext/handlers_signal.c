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

// Set while SIGVTALRM is blocked by us; a bailout skips the unblocking, which is then done at post-deactivate.
ZEND_TLS bool dd_signal_blocked = false;

static bool dd_block_signal(void) {
    if (dd_signal_blocked) {
        // Nested blocking (e.g. a stream notification callback calling a blocked function) must not unblock early
        return false;
    }

    sigset_t x, old;
    sigemptyset(&x);
    sigaddset(&x, SIGVTALRM);
    sigprocmask(SIG_BLOCK, &x, &old);
    // Do not unblock it if it was blocked by someone else
    dd_signal_blocked = !sigismember(&old, SIGVTALRM);
    return dd_signal_blocked;
}

static void dd_unblock_signal_now(void) {
    sigset_t x;
    sigemptyset(&x);
    sigaddset(&x, SIGVTALRM);
    sigprocmask(SIG_UNBLOCK, &x, NULL);
    dd_signal_blocked = false;
}

static void dd_unblock_signal(bool blocked) {
    if (!blocked) {
        return;
    }

    dd_unblock_signal_now();
    // ensures no double-processing when the signal was delivered normally.
    if (REMOTE_CONFIG_NEEDS_POLLING_AFTER_SIGNAL) {
        datadog_check_for_new_config_now();
    }
}

#define DD_SIGNAL_GUARDED(call) do { \
        bool blocked = dd_block_signal(); \
        call; \
        dd_unblock_signal(blocked); \
    } while (0)

void datadog_signal_block_post_deactivate(void) {
    if (dd_signal_blocked) {
        dd_unblock_signal_now();
    }
}

/* We need to do signal blocking for the remote config signaling to not interfere with some PHP functions.
 * See e.g. https://github.com/php/php-src/issues/16800
 * I don't know the full problem space, so I expect there might be functions missing here, and we need to eventually expand this list.
 */
static void dd_handle_signal(zif_handler original_function, INTERNAL_FUNCTION_PARAMETERS) {
    DD_SIGNAL_GUARDED(original_function(INTERNAL_FUNCTION_PARAM_PASSTHRU));
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

BLOCK(BLOCKSIGFN)

BLOCKMETH(BLOCKSIGMETH)

#if PHP_VERSION_ID < 80500
/* Before php-src commit 69765d92 (PHP 8.2.27, 8.3.15, 8.4.2) php_network_connect_socket() did not retry the poll() on EINTR,
 * failing the connect instead. Everything ending up in php_network_connect_socket() needs to be guarded on these versions:
 * the stream transports (tcp, udp, unix, ssl, tls, ...) via php_stream_xport_create() and ext/ftp (already guarded above).
 * Stream wrappers connecting via transports (http, ftp) are guarded at the wrapper level, see dd_signal_stream_wrapper.
 */
#define BLOCK_CONNECT(x) \
    x(fsockopen) \
    x(pfsockopen) \
    x(stream_socket_client) \
    x(memcache_connect) \
    x(memcache_pconnect)

#define BLOCKMETH_CONNECT(x) \
    x(soapclient, __dorequest) \
    x(redis, connect) \
    x(redis, pconnect) \
    x(redis, open) \
    x(redis, popen) \
    x(rediscluster, __construct) \
    x(redisarray, __construct) \
    x(memcache, connect) \
    x(memcache, pconnect) \
    x(memcachepool, connect)

BLOCK_CONNECT(BLOCKSIGFN)

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

// The wrapper passed to the ops is not necessarily ours (e.g. the http stream tracing copies it), hence one set of ops per wrapper
#define DD_SIGNAL_STREAM_WRAPPERS(x) \
    x(0, http) \
    x(1, https) \
    x(2, ftp) \
    x(3, ftps)

typedef struct {
    php_stream_wrapper wrapper;
    php_stream_wrapper_ops wops;
    const php_stream_wrapper_ops *orig_wops;
} dd_signal_stream_wrapper;

static dd_signal_stream_wrapper dd_signal_stream_wrappers[4];

static php_stream *dd_signal_stream_opener(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    php_stream *stream = NULL;
    DD_SIGNAL_GUARDED(stream = orig->stream_opener(wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC));
    return stream;
}

static int dd_signal_url_stat(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *url, int flags, php_stream_statbuf *ssb, php_stream_context *context) {
    int ret = -1;
    DD_SIGNAL_GUARDED(ret = orig->url_stat(wrapper, url, flags, ssb, context));
    return ret;
}

static php_stream *dd_signal_dir_opener(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) {
    php_stream *stream = NULL;
    DD_SIGNAL_GUARDED(stream = orig->dir_opener(wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC));
    return stream;
}

static int dd_signal_unlink(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) {
    int ret = 0;
    DD_SIGNAL_GUARDED(ret = orig->unlink(wrapper, url, options, context));
    return ret;
}

static int dd_signal_rename(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *url_from, const char *url_to, int options, php_stream_context *context) {
    int ret = 0;
    DD_SIGNAL_GUARDED(ret = orig->rename(wrapper, url_from, url_to, options, context));
    return ret;
}

static int dd_signal_stream_mkdir(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *url, int mode, int options, php_stream_context *context) {
    int ret = 0;
    DD_SIGNAL_GUARDED(ret = orig->stream_mkdir(wrapper, url, mode, options, context));
    return ret;
}

static int dd_signal_stream_rmdir(const php_stream_wrapper_ops *orig, php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) {
    int ret = 0;
    DD_SIGNAL_GUARDED(ret = orig->stream_rmdir(wrapper, url, options, context));
    return ret;
}

#define DD_SIGNAL_STREAM_WRAPPER_OPS(idx, protocol) \
    static php_stream *dd_signal_stream_opener_##protocol(php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) { \
        return dd_signal_stream_opener(dd_signal_stream_wrappers[idx].orig_wops, wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC); \
    } \
    static int dd_signal_url_stat_##protocol(php_stream_wrapper *wrapper, const char *url, int flags, php_stream_statbuf *ssb, php_stream_context *context) { \
        return dd_signal_url_stat(dd_signal_stream_wrappers[idx].orig_wops, wrapper, url, flags, ssb, context); \
    } \
    static php_stream *dd_signal_dir_opener_##protocol(php_stream_wrapper *wrapper, const char *filename, const char *mode, int options, zend_string **opened_path, php_stream_context *context STREAMS_DC) { \
        return dd_signal_dir_opener(dd_signal_stream_wrappers[idx].orig_wops, wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC); \
    } \
    static int dd_signal_unlink_##protocol(php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) { \
        return dd_signal_unlink(dd_signal_stream_wrappers[idx].orig_wops, wrapper, url, options, context); \
    } \
    static int dd_signal_rename_##protocol(php_stream_wrapper *wrapper, const char *url_from, const char *url_to, int options, php_stream_context *context) { \
        return dd_signal_rename(dd_signal_stream_wrappers[idx].orig_wops, wrapper, url_from, url_to, options, context); \
    } \
    static int dd_signal_stream_mkdir_##protocol(php_stream_wrapper *wrapper, const char *url, int mode, int options, php_stream_context *context) { \
        return dd_signal_stream_mkdir(dd_signal_stream_wrappers[idx].orig_wops, wrapper, url, mode, options, context); \
    } \
    static int dd_signal_stream_rmdir_##protocol(php_stream_wrapper *wrapper, const char *url, int options, php_stream_context *context) { \
        return dd_signal_stream_rmdir(dd_signal_stream_wrappers[idx].orig_wops, wrapper, url, options, context); \
    }

DD_SIGNAL_STREAM_WRAPPERS(DD_SIGNAL_STREAM_WRAPPER_OPS)

// Wraps the operations of the URL wrappers which may connect sockets: these are used by any function accepting a filename
#define DD_SIGNAL_BLOCK_STREAM_WRAPPER(idx, protocol) do { \
        HashTable *wrappers = php_stream_get_url_stream_wrappers_hash_global(); \
        const php_stream_wrapper *orig = zend_hash_str_find_ptr(wrappers, ZEND_STRL(#protocol)); \
        if (orig && orig->wops) { \
            dd_signal_stream_wrapper *slot = &dd_signal_stream_wrappers[idx]; \
            slot->orig_wops = orig->wops; \
            slot->wrapper = *orig; \
            slot->wops = *orig->wops; \
            slot->wrapper.wops = &slot->wops; \
            if (slot->wops.stream_opener) { slot->wops.stream_opener = dd_signal_stream_opener_##protocol; } \
            if (slot->wops.url_stat) { slot->wops.url_stat = dd_signal_url_stat_##protocol; } \
            if (slot->wops.dir_opener) { slot->wops.dir_opener = dd_signal_dir_opener_##protocol; } \
            if (slot->wops.unlink) { slot->wops.unlink = dd_signal_unlink_##protocol; } \
            if (slot->wops.rename) { slot->wops.rename = dd_signal_rename_##protocol; } \
            if (slot->wops.stream_mkdir) { slot->wops.stream_mkdir = dd_signal_stream_mkdir_##protocol; } \
            if (slot->wops.stream_rmdir) { slot->wops.stream_rmdir = dd_signal_stream_rmdir_##protocol; } \
            zend_hash_str_update_ptr(wrappers, ZEND_STRL(#protocol), &slot->wrapper); \
        } \
    } while (0);
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

        DD_SIGNAL_STREAM_WRAPPERS(DD_SIGNAL_BLOCK_STREAM_WRAPPER)
    }
#endif
}
