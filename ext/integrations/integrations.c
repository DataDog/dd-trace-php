#include "integrations.h"

#include "../configuration.h"
#include "../telemetry.h"
#include <components/log/log.h>
#include <exceptions/exceptions.h>
#include <hook/hook.h>
#include <sandbox/sandbox.h>
#undef INTEGRATION

#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)             \
    dd_hook_method_and_unhook_on_first_call((zai_str)ZAI_STRL(class), (zai_str)ZAI_STRL(fname), \
                          (zai_str)ZAI_STRL(integration_name), (ddtrace_integration_name)-1, false)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, (zai_str)ZAI_STRL(Class), (zai_str)ZAI_STRL(fname), \
                                         (zai_str)ZAI_STRL(integration), false)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD_POST(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, (zai_str)ZAI_STRL(Class), (zai_str)ZAI_STRL(fname), \
                                         (zai_str)ZAI_STRL(integration), true)

#define DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(name, fname, integration)                           \
    dd_set_up_deferred_loading_by_method(name, (zai_str)ZAI_STR_EMPTY, (zai_str)ZAI_STRL(fname), \
                                         (zai_str)ZAI_STRL(integration), false)

#define INTEGRATION(id, lcname, ...)                                    \
    {                                                                  \
        .name = DDTRACE_INTEGRATION_##id,                              \
        .name_ucase = #id,                                             \
        .name_lcase = (lcname),                                        \
        .name_len = sizeof(lcname) - 1,                                \
        .is_enabled = get_DD_TRACE_##id##_ENABLED,              \
        .is_analytics_enabled = get_DD_TRACE_##id##_ANALYTICS_ENABLED, \
        .get_sample_rate = get_DD_TRACE_##id##_ANALYTICS_SAMPLE_RATE,  \
        .aux = {0},                                                    \
    },
ddtrace_integration ddtrace_integrations[] = {DD_INTEGRATIONS};
size_t ddtrace_integrations_len = sizeof ddtrace_integrations / sizeof ddtrace_integrations[0];

// Map of lowercase strings to the ddtrace_integration equivalent
static HashTable _dd_string_to_integration_name_map;

static void _dd_add_integration_to_map(char* name, size_t name_len, ddtrace_integration* integration);

void ddtrace_integrations_mshutdown(void) { zend_hash_destroy(&_dd_string_to_integration_name_map); }

typedef struct {
    ddtrace_integration_name name;
    zend_string *classname;
    zai_str scope;
    zai_str function;
    zend_long id;
} dd_integration_aux;

void dd_integration_aux_free(void *auxiliary) {
    free(auxiliary);
}

#if PHP_VERSION_ID < 80000
#define LAST_ERROR_STRING PG(last_error_message)
#else
#define LAST_ERROR_STRING ZSTR_VAL(PG(last_error_message))
#endif
#if PHP_VERSION_ID < 80100
#define LAST_ERROR_FILE PG(last_error_file)
#else
#define LAST_ERROR_FILE ZSTR_VAL(PG(last_error_file))
#endif

static void dd_invoke_integration_loader_and_unhook_posthook(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    (void) dynamic, (void) retval, (void) invocation;

    if (!get_DD_TRACE_ENABLED()) {
        return;
    }

    dd_integration_aux *aux = auxiliary;
    volatile bool unload_hooks = true;

    if (aux->name == -1u || ddtrace_config_integration_enabled(aux->name)) {
        if (aux->name != -1u) {
            ddtrace_telemetry_notify_integration(ddtrace_integrations[aux->name].name_lcase, ddtrace_integrations[aux->name].name_len);
        } else {
            ddtrace_telemetry_notify_integration(ZSTR_VAL(aux->classname), ZSTR_LEN(aux->classname));
        }

        zai_sandbox sandbox;
        zai_sandbox_open(&sandbox);
        bool success = false;
        zend_try {
            do {
                zend_class_entry *ce = zend_lookup_class(aux->classname);
                if (!ce) {
                    LOG(WARN, "Error loading deferred integration %s: Class not loaded and not autoloadable", ZSTR_VAL(aux->classname));
                    success = true;
                    break;
                }

                if (!instanceof_function(ce, ddtrace_ce_integration)) {
                    LOG(WARN, "Error loading deferred integration %s: Class is not an instance of DDTrace\\Integration", ZSTR_VAL(aux->classname));
                    success = true;
                    break;
                }

                zval obj;
                if (!zai_symbol_new(&obj, ce, 0)) {
                    break;
                }

                zval rv;
                zval *thisp = getThis();
                if (thisp) {
                    success = zai_symbol_call_named(ZAI_SYMBOL_SCOPE_OBJECT, &obj, &(zai_str) ZAI_STRL("init"), &rv, 1 | ZAI_SYMBOL_SANDBOX, &sandbox, thisp);
                } else {
                    success = zai_symbol_call_named(ZAI_SYMBOL_SCOPE_OBJECT, &obj, &(zai_str) ZAI_STRL("init"), &rv, 0 | ZAI_SYMBOL_SANDBOX, &sandbox);
                }

                zval_ptr_dtor(&obj);

                if (success && get_DD_TRACE_ENABLED()) {
                    switch (Z_LVAL(rv)) {
                        case DD_TRACE_INTEGRATION_LOADED:
                            LOG(DEBUG, "Loaded integration %s", ZSTR_VAL(aux->classname));
                            break;
                        case DD_TRACE_INTEGRATION_NOT_LOADED:
                            LOG(DEBUG, "Integration %s not available. New attempts WILL NOT be performed.", ZSTR_VAL(aux->classname));
                            break;
                        case DD_TRACE_INTEGRATION_NOT_AVAILABLE:
                            LOG(DEBUG, "Integration {name} not loaded. New attempts might be performed.", ZSTR_VAL(aux->classname));
                            unload_hooks = false;
                            break;
                        default:
                            LOG(WARN, "Invalid value returning by integration loader for %s: " ZEND_LONG_FMT, ZSTR_VAL(aux->classname), Z_LVAL(rv));
                            break;
                    }
                }
            } while (0);
        } zend_catch {
        } zend_end_try();
        if ((!success || PG(last_error_message)) && get_DD_TRACE_ENABLED()) {
            LOGEV(WARN, {
                zend_object *ex = EG(exception);
                if (ex) {
                    const char *type = ZSTR_VAL(ex->ce->name);
                    const char *msg = instanceof_function(ex->ce, zend_ce_throwable) ? ZSTR_VAL(zai_exception_message(ex)) : "<exit>";
                    log("%s thrown in ddtrace's integration autoloader for %s: %s",
                        type, ZSTR_VAL(aux->classname), msg);
                } else if (PG(last_error_message)) {
                    log("Error raised in ddtrace's integration autoloader for %s: %s in %s on line %d",
                        ZSTR_VAL(aux->classname), LAST_ERROR_STRING, LAST_ERROR_FILE, PG(last_error_lineno));
                }
            })
        }
        zai_sandbox_close(&sandbox);
    }

    if (unload_hooks) {
        if (aux->name != -1u) {
            for (dd_integration_aux **auxArray = (dd_integration_aux **) ddtrace_integrations[aux->name].aux; *auxArray; ++auxArray) {
                zai_hook_remove((*auxArray)->scope, (*auxArray)->function, (*auxArray)->id);
            }
        } else {
            zai_hook_remove_resolved(zai_hook_install_address(EX(func)), aux->id);
        }
    }
}

static bool dd_invoke_integration_loader_and_unhook_prehook(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_invoke_integration_loader_and_unhook_posthook(invocation, execute_data, &EG(uninitialized_zval), auxiliary, dynamic);
    return true;
}

static void dd_hook_method_and_unhook_on_first_call(zai_str Class, zai_str method, zai_str callback, ddtrace_integration_name name, bool posthook) {
    dd_integration_aux *aux = malloc(sizeof(*aux));
    aux->name = name;
    aux->classname = zend_string_init_interned(callback.ptr, callback.len, 1);
    aux->id = zai_hook_install(Class, method,
            posthook ? NULL : dd_invoke_integration_loader_and_unhook_prehook,
            posthook ? dd_invoke_integration_loader_and_unhook_posthook : NULL,
            ZAI_HOOK_AUX(aux, dd_integration_aux_free),
            0);
    aux->scope = Class;
    aux->function = method;

    if (name != -1u) {
        void **auxArray = ddtrace_integrations[name].aux;
        while (*auxArray) {
            ++auxArray;
        }
        *auxArray = aux;
    }
}

static void dd_load_test_integrations(void) {
    char* test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }

    DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "ddtrace\\test\\testsandboxedintegration");
    // DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK);
}

static void dd_set_up_deferred_loading_by_method(ddtrace_integration_name name, zai_str Class,
                                                 zai_str method, zai_str integration, bool posthook) {
    // We unconditionally install our hooks. We skip it on hit.
    dd_hook_method_and_unhook_on_first_call(Class, method, integration, name, posthook);
}

void ddtrace_integrations_minit(void) {
    zend_hash_init(&_dd_string_to_integration_name_map, ddtrace_integrations_len, NULL, NULL, 1);

    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        memset(ddtrace_integrations[i].aux, 0, sizeof(ddtrace_integrations[i].aux));
        char *name = ddtrace_integrations[i].name_lcase;
        size_t name_len = ddtrace_integrations[i].name_len;
        _dd_add_integration_to_map(name, name_len, &ddtrace_integrations[i]);
    }

    dd_load_test_integrations();

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_AMQP, "PhpAmqpLib\\Connection\\AbstractConnection", "__construct",
                                        "DDTrace\\Integrations\\AMQP\\AMQPIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "App", "init",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "Dispatcher", "__construct",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "App\\Application", "__construct",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "Cake\\Http\\Server", "__construct",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "exec",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "system",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "passthru",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "shell_exec",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "popen",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_EXEC, "proc_open",
                                         "DDTrace\\Integrations\\Exec\\ExecIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_CURL, "curl_exec",
                                           "DDTrace\\Integrations\\Curl\\CurlIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_CURL, "curl_multi_exec",
                                           "DDTrace\\Integrations\\Curl\\CurlIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_DRUPAL, "Drupal\\Core\\DrupalKernel", "__construct",
                                         "DDTrace\\Integrations\\Drupal\\DrupalIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD_POST(DDTRACE_INTEGRATION_CODEIGNITER, "CI_Router", "_set_routing",
                                         "DDTrace\\Integrations\\CodeIgniter\\V2\\CodeIgniterIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELASTICSEARCH, "elasticsearch\\client", "__construct",
                                         "DDTrace\\Integrations\\ElasticSearch\\V1\\ElasticSearchIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELASTICSEARCH, "elastic\\elasticsearch\\client", "__construct",
                                         "DDTrace\\Integrations\\ElasticSearch\\V8\\ElasticSearchIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELOQUENT, "Illuminate\\Database\\Eloquent\\Builder", "__construct",
                                         "DDTrace\\Integrations\\Eloquent\\EloquentIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELOQUENT, "Illuminate\\Database\\Eloquent\\Model", "__construct",
                                         "DDTrace\\Integrations\\Eloquent\\EloquentIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELOQUENT, "Illuminate\\Database\\Eloquent\\Model", "destroy",
                                         "DDTrace\\Integrations\\Eloquent\\EloquentIntegration");

#if PHP_VERSION_ID >= 80200
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_FRANKENPHP, "frankenphp_handle_request",
                                          "DDTrace\\Integrations\\Frankenphp\\FrankenphpIntegration");
#endif

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_GUZZLE, "GuzzleHttp\\Client", "__construct",
                                         "DDTrace\\Integrations\\Guzzle\\GuzzleIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LAMINAS, "Laminas\\Mvc\\Application", "init",
                                         "DDTrace\\Integrations\\Laminas\\LaminasIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LAMINAS, "Laminas\\Mvc\\Application", "bootstrap",
                                             "DDTrace\\Integrations\\Laminas\\LaminasIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LAMINAS, "Laminas\\Mvc\\Application", "__construct",
                                         "DDTrace\\Integrations\\Laminas\\LaminasIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVEL, "Illuminate\\Foundation\\Application", "__construct",
                                         "DDTrace\\Integrations\\Laravel\\LaravelIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVEL, "Laravel\\Lumen\\Application", "__construct",
                                         "DDTrace\\Integrations\\Laravel\\LaravelIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LUMEN, "Laravel\\Lumen\\Application", "__construct",
                                         "DDTrace\\Integrations\\Lumen\\LumenIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MAGENTO, "Magento\\Framework\\App\\Bootstrap", "__construct",
                                         "DDTrace\\Integrations\\Magento\\MagentoIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MAGENTO, "Magento\\Framework\\Console\\Cli", "__construct",
                                         "DDTrace\\Integrations\\Magento\\MagentoIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHE, "Memcache", "connect",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHE, "Memcache", "pconnect",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHE, "Memcache", "addServer",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MEMCACHE, "memcache_connect",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MEMCACHE, "memcache_pconnect",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MEMCACHE, "memcache_add_server",
                                         "DDTrace\\Integrations\\Memcache\\MemcacheIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHED, "Memcached", "__construct",
                                         "DDTrace\\Integrations\\Memcached\\MemcachedIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "emergency",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "alert",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "critical",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "error",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "warning",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "notice",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "info",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "debug",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LOGS, "Psr\\Log\\LoggerInterface", "log",
                                         "DDTrace\\Integrations\\Logs\\LogsIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGO, "MongoClient", "__construct",
                                         "DDTrace\\Integrations\\Mongo\\MongoIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\manager", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\query", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\command", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\bulkwrite", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MYSQLI, "mysqli_init",
                                           "DDTrace\\Integrations\\Mysqli\\MysqliIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MYSQLI, "mysqli_connect",
                                           "DDTrace\\Integrations\\Mysqli\\MysqliIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_MYSQLI, "mysqli_real_connect",
                                           "DDTrace\\Integrations\\Mysqli\\MysqliIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MYSQLI, "mysqli", "__construct",
                                         "DDTrace\\Integrations\\Mysqli\\MysqliIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_NETTE, "Nette\\Configurator", "__construct",
                                         "DDTrace\\Integrations\\Nette\\NetteIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_OPENAI, "OpenAI\\Client", "__construct",
                                         "DDTrace\\Integrations\\OpenAI\\OpenAIIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_OPENAI, "OpenAI\\Factory", "make",
                                         "DDTrace\\Integrations\\OpenAI\\OpenAIIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_OPENAI, "OpenAI\\Transporters\\HttpTransporter", "__construct",
                                         "DDTrace\\Integrations\\OpenAI\\OpenAIIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_NETTE, "Nette\\Bootstrap\\Configurator", "__construct",
                                         "DDTrace\\Integrations\\Nette\\NetteIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_PCNTL, "pcntl_fork",
                                         "DDTrace\\Integrations\\Pcntl\\PcntlIntegration");
#if PHP_VERSION_ID >= 80100
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_PCNTL, "pcntl_rfork",
                                         "DDTrace\\Integrations\\Pcntl\\PcntlIntegration");
#endif
#if PHP_VERSION_ID >= 80200
    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_PCNTL, "pcntl_forkx",
                                         "DDTrace\\Integrations\\Pcntl\\PcntlIntegration");
#endif

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PDO, "PDO", "__construct",
                                         "DDTrace\\Integrations\\PDO\\PDOIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PHPREDIS, "Redis", "__construct",
                                         "DDTrace\\Integrations\\PHPRedis\\PHPRedisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PHPREDIS, "RedisCluster", "__construct",
                                         "DDTrace\\Integrations\\PHPRedis\\PHPRedisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PREDIS, "Predis\\Client", "__construct",
                                         "DDTrace\\Integrations\\Predis\\PredisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PSR18, "Psr\\Http\\Client\\ClientInterface", "sendRequest",
                                         "DDTrace\\Integrations\\Psr18\\Psr18Integration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ROADRUNNER, "Spiral\\RoadRunner\\Http\\HttpWorker", "waitRequest",
                                         "DDTrace\\Integrations\\Roadrunner\\RoadrunnerIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SLIM, "Slim\\App", "__construct",
                                         "DDTrace\\Integrations\\Slim\\SlimIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SWOOLE, "Swoole\\Http\\Server", "__construct",
                                         "DDTrace\\Integrations\\Swoole\\SwooleIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVELQUEUE, "Illuminate\\Queue\\Worker", "__construct",
                                         "DDTrace\\Integrations\\LaravelQueue\\LaravelQueueIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVELQUEUE, "Illuminate\\Contracts\\Queue\\Queue", "push",
                                         "DDTrace\\Integrations\\LaravelQueue\\LaravelQueueIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVELQUEUE, "Illuminate\\Contracts\\Queue\\Queue", "later",
                                             "DDTrace\\Integrations\\LaravelQueue\\LaravelQueueIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVELQUEUE, "Illuminate\\Bus\\PendingBatch", "__construct",
                                         "DDTrace\\Integrations\\LaravelQueue\\LaravelQueueIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LARAVELQUEUE, "Illuminate\\Foundation\\Bus\\PendingChain", "__construct",
                                             "DDTrace\\Integrations\\LaravelQueue\\LaravelQueueIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Symfony\\Component\\HttpKernel\\Kernel", "__construct",
                                         "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Symfony\\Component\\HttpKernel\\HttpKernel", "__construct",
                                         "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Magento\\Framework\\Console\\Cli", "__construct",
                                             "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Drupal\\Core\\DrupalKernel", "__construct",
                                             "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONYMESSENGER, "Symfony\\Component\\Messenger\\Worker", "__construct",
                                             "DDTrace\\Integrations\\SymfonyMessenger\\SymfonyMessengerIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONYMESSENGER, "Symfony\\Component\\Messenger\\MessageBusInterface", "dispatch",
                                         "DDTrace\\Integrations\\SymfonyMessenger\\SymfonyMessengerIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_SQLSRV, "sqlsrv_connect",
                                         "DDTrace\\Integrations\\SQLSRV\\SQLSRVIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(DDTRACE_INTEGRATION_WORDPRESS, "wp_check_php_mysql_versions",
                                           "DDTrace\\Integrations\\WordPress\\WordPressIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_YII, "yii\\di\\Container", "__construct",
                                         "DDTrace\\Integrations\\Yii\\YiiIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ZENDFRAMEWORK, "Zend_Controller_Plugin_Broker", "preDispatch",
                                         "DDTrace\\Integrations\\ZendFramework\\ZendFrameworkIntegration");
}

ddtrace_integration* ddtrace_get_integration_from_string(ddtrace_string integration) {
    return zend_hash_str_find_ptr(&_dd_string_to_integration_name_map, integration.ptr, integration.len);
}

static void _dd_add_integration_to_map(char* name, size_t name_len, ddtrace_integration* integration) {
    zend_hash_str_add_ptr(&_dd_string_to_integration_name_map, name, name_len, integration);
    ZEND_ASSERT(strlen(integration->name_ucase) == name_len);
    ZEND_ASSERT(DDTRACE_LONGEST_INTEGRATION_NAME_LEN >= name_len);
}
