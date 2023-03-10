#include "integrations.h"

#include "../configuration.h"
#include "../logging.h"
#include <hook/hook.h>
#undef INTEGRATION

#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)             \
    dd_hook_method_and_unhook_on_first_call(ZAI_STRL_VIEW(class), ZAI_STRL_VIEW(fname), \
                          ZAI_STRL_VIEW(integration_name), (ddtrace_integration_name)-1, false)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, ZAI_STRL_VIEW(Class), ZAI_STRL_VIEW(fname), \
                                         ZAI_STRL_VIEW(integration), false)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD_POST(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, ZAI_STRL_VIEW(Class), ZAI_STRL_VIEW(fname), \
                                         ZAI_STRL_VIEW(integration), true)

#define DD_SET_UP_DEFERRED_LOADING_BY_FUNCTION(name, fname, integration)                           \
    dd_set_up_deferred_loading_by_method(name, ZAI_STRING_EMPTY, ZAI_STRL_VIEW(fname), \
                                         ZAI_STRL_VIEW(integration), false)

#define INTEGRATION(id, lcname)                                        \
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
    zai_string_view scope;
    zai_string_view function;
    zend_long id;
} dd_integration_aux;

void dd_integration_aux_free(void *auxiliary) {
    dd_integration_aux *aux = auxiliary;
    zend_string_release(aux->classname);
    free(aux);
}

static void dd_invoke_integration_loader_and_unhook_posthook(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
    (void) dynamic, (void) retval, (void) invocation;

    dd_integration_aux *aux = auxiliary;
    zval integration;
    ZVAL_STR(&integration, aux->classname);

    if (aux->name == -1u || ddtrace_config_integration_enabled(aux->name)) {
        zval rv;
        bool success;
        zval *thisp = getThis();
        if (thisp) {
            success = zai_symbol_call_literal(ZEND_STRL("ddtrace\\integrations\\load_deferred_integration"), &rv, 2, &integration, thisp);
        } else {
            success = zai_symbol_call_literal(ZEND_STRL("ddtrace\\integrations\\load_deferred_integration"), &rv, 1, &integration);
        }

        if (UNEXPECTED(!success)) {
            ddtrace_log_debugf(
                    "Error loading deferred integration '%s' from DDTrace\\Integrations\\load_deferred_integration",
                    Z_STRVAL(integration));
        }
    }

    if (aux->name != -1u) {
        for (dd_integration_aux **auxArray = (dd_integration_aux **) ddtrace_integrations[aux->name].aux; *auxArray; ++auxArray) {
            zai_hook_remove((*auxArray)->scope, (*auxArray)->function, (*auxArray)->id);
        }
    } else {
        zai_hook_remove_resolved(zai_hook_install_address(EX(func)), aux->id);
    }
}

static bool dd_invoke_integration_loader_and_unhook_prehook(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
    dd_invoke_integration_loader_and_unhook_posthook(invocation, execute_data, &EG(uninitialized_zval), auxiliary, dynamic);
    return true;
}

static void dd_hook_method_and_unhook_on_first_call(zai_string_view Class, zai_string_view method, zai_string_view callback, ddtrace_integration_name name, bool posthook) {
    dd_integration_aux *aux = malloc(sizeof(*aux));
    aux->name = name;
    aux->classname = zend_string_init(callback.ptr, callback.len, 1);
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

static void dd_set_up_deferred_loading_by_method(ddtrace_integration_name name, zai_string_view Class,
                                                 zai_string_view method, zai_string_view integration, bool posthook) {
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

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "App", "init",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_CAKEPHP, "Dispatcher", "__construct",
                                         "DDTrace\\Integrations\\CakePHP\\CakePHPIntegration");

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

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_LUMEN, "Laravel\\Lumen\\Application", "__construct",
                                         "DDTrace\\Integrations\\Lumen\\LumenIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHED, "Memcached", "__construct",
                                         "DDTrace\\Integrations\\Memcached\\MemcachedIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\manager", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\query", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\command", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MONGODB, "mongodb\\driver\\bulkwrite", "__construct",
                                         "DDTrace\\Integrations\\MongoDB\\MongoDBIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_NETTE, "Nette\\Configurator", "__construct",
                                         "DDTrace\\Integrations\\Nette\\NetteIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_NETTE, "Nette\\Bootstrap\\Configurator", "__construct",
                                         "DDTrace\\Integrations\\Nette\\NetteIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PDO, "PDO", "__construct",
                                         "DDTrace\\Integrations\\PDO\\PDOIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PHPREDIS, "Redis", "__construct",
                                         "DDTrace\\Integrations\\PHPRedis\\PHPRedisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PHPREDIS, "RedisCluster", "__construct",
                                         "DDTrace\\Integrations\\PHPRedis\\PHPRedisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_PREDIS, "Predis\\Client", "__construct",
                                         "DDTrace\\Integrations\\Predis\\PredisIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ROADRUNNER, "Spiral\\RoadRunner\\Http\\HttpWorker", "waitRequest",
                                         "DDTrace\\Integrations\\Roadrunner\\RoadrunnerIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SLIM, "Slim\\App", "__construct",
                                         "DDTrace\\Integrations\\Slim\\SlimIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Symfony\\Component\\HttpKernel\\Kernel", "__construct",
                                         "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");
    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SYMFONY, "Symfony\\Component\\HttpKernel\\HttpKernel", "__construct",
                                         "DDTrace\\Integrations\\Symfony\\SymfonyIntegration");

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
