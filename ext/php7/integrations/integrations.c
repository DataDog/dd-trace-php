#include "integrations.h"

#include "ext/php7/configuration.h"
#include "ext/php7/ddtrace_string.h"

#define DDTRACE_DEFERRED_INTEGRATION_LOADER(class, fname, integration_name)             \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(integration_name), DDTRACE_DISPATCH_DEFERRED_LOADER)

#define DD_SET_UP_DEFERRED_LOADING_BY_METHOD(name, Class, fname, integration)                                \
    dd_set_up_deferred_loading_by_method(name, DDTRACE_STRING_LITERAL(Class), DDTRACE_STRING_LITERAL(fname), \
                                         DDTRACE_STRING_LITERAL(integration))
/**
 * DDTRACE_INTEGRATION_TRACE(class, fname, callable, options)
 *
 * This macro can be used to assign a tracing callable to Class, Method/Function name combination
 * the callable can be any callable string thats recognized by PHP. It needs to have the signature
 * expected by normal DDTrace.
 *
 * function tracing_function(SpanData $span, array $args) { }
 *
 * options need to specify either DDTRACE_DISPATCH_POSTHOOK or DDTRACE_DISPATCH_PREHOOK
 * in order for the callable to be called by the hooks
 **/
#define DDTRACE_INTEGRATION_TRACE(class, fname, callable, options)                      \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class), DDTRACE_STRING_LITERAL(fname), \
                          DDTRACE_STRING_LITERAL(callable), options)

ddtrace_integration ddtrace_integrations[] = {
    {DDTRACE_INTEGRATION_CAKEPHP, "CAKEPHP", ZEND_STRL("cakephp")},
    {DDTRACE_INTEGRATION_CODEIGNITER, "CODEIGNITER", ZEND_STRL("codeigniter")},
    {DDTRACE_INTEGRATION_CURL, "CURL", ZEND_STRL("curl")},
    {DDTRACE_INTEGRATION_ELASTICSEARCH, "ELASTICSEARCH", ZEND_STRL("elasticsearch")},
    {DDTRACE_INTEGRATION_ELOQUENT, "ELOQUENT", ZEND_STRL("eloquent")},
    {DDTRACE_INTEGRATION_GUZZLE, "GUZZLE", ZEND_STRL("guzzle")},
    {DDTRACE_INTEGRATION_LARAVEL, "LARAVEL", ZEND_STRL("laravel")},
    {DDTRACE_INTEGRATION_LUMEN, "LUMEN", ZEND_STRL("lumen")},
    {DDTRACE_INTEGRATION_MEMCACHED, "MEMCACHED", ZEND_STRL("memcached")},
    {DDTRACE_INTEGRATION_MONGO, "MONGO", ZEND_STRL("mongo")},
    {DDTRACE_INTEGRATION_MYSQLI, "MYSQLI", ZEND_STRL("mysqli")},
    {DDTRACE_INTEGRATION_NETTE, "NETTE", ZEND_STRL("nette")},
    {DDTRACE_INTEGRATION_PDO, "PDO", ZEND_STRL("pdo")},
    {DDTRACE_INTEGRATION_PHPREDIS, "PHPREDIS", ZEND_STRL("phpredis")},
    {DDTRACE_INTEGRATION_PREDIS, "PREDIS", ZEND_STRL("predis")},
    {DDTRACE_INTEGRATION_SLIM, "SLIM", ZEND_STRL("slim")},
    {DDTRACE_INTEGRATION_SYMFONY, "SYMFONY", ZEND_STRL("symfony")},
    {DDTRACE_INTEGRATION_WEB, "WEB", ZEND_STRL("web")},
    {DDTRACE_INTEGRATION_WORDPRESS, "WORDPRESS", ZEND_STRL("wordpress")},
    {DDTRACE_INTEGRATION_YII, "YII", ZEND_STRL("yii")},
    {DDTRACE_INTEGRATION_ZENDFRAMEWORK, "ZENDFRAMEWORK", ZEND_STRL("zendframework")},
};
size_t ddtrace_integrations_len = sizeof ddtrace_integrations / sizeof ddtrace_integrations[0];

// Map of lowercase strings to the ddtrace_integration equivalent
static HashTable _dd_string_to_integration_name_map;

static void _dd_add_integration_to_map(char* name, size_t name_len, ddtrace_integration* integration);

void ddtrace_integrations_minit(void) {
    zend_hash_init(&_dd_string_to_integration_name_map, ddtrace_integrations_len, NULL, NULL, 1);

    for (size_t i = 0; i < ddtrace_integrations_len; ++i) {
        char* name = ddtrace_integrations[i].name_lcase;
        size_t name_len = ddtrace_integrations[i].name_len;
        _dd_add_integration_to_map(name, name_len, &ddtrace_integrations[i]);
    }
}

void ddtrace_integrations_mshutdown(void) { zend_hash_destroy(&_dd_string_to_integration_name_map); }

#define DDTRACE_KNOWN_INTEGRATION(class_str, fname_str)                                         \
    ddtrace_hook_callable(DDTRACE_STRING_LITERAL(class_str), DDTRACE_STRING_LITERAL(fname_str), \
                          DDTRACE_STRING_LITERAL(NULL), DDTRACE_DISPATCH_POSTHOOK)

/* Due to negative lookup caching, we need to have a list of all things we
 * might instrument so that if a call is made to something we want to later
 * instrument but is not currently instrumented, that we don't cache this.
 *
 * We should improve how this list is made in the future instead of hard-
 * coding known integrations (and for now only the problematic ones).
 */
static void dd_register_known_calls(void) {
    DDTRACE_KNOWN_INTEGRATION("wpdb", "query");
    DDTRACE_KNOWN_INTEGRATION("illuminate\\events\\dispatcher", "fire");
}

static void dd_load_test_integrations(void) {
    char* test_deferred = getenv("_DD_LOAD_TEST_INTEGRATIONS");
    if (!test_deferred) {
        return;
    }

    DDTRACE_DEFERRED_INTEGRATION_LOADER("test", "public_static_method", "ddtrace\\test\\testsandboxedintegration");
    DDTRACE_INTEGRATION_TRACE("test", "automaticaly_traced_method", "tracing_function", DDTRACE_DISPATCH_POSTHOOK);
}

static void dd_set_up_deferred_loading_by_method(ddtrace_integration_name name, ddtrace_string Class,
                                                 ddtrace_string method, ddtrace_string integration) {
    if (!ddtrace_config_integration_enabled_ex(name)) {
        return;
    }

    ddtrace_hook_callable(Class, method, integration, DDTRACE_DISPATCH_DEFERRED_LOADER);
}

void ddtrace_integrations_rinit(void) {
    dd_register_known_calls();
    dd_load_test_integrations();

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_ELASTICSEARCH, "elasticsearch\\client", "__construct",
                                         "DDTrace\\Integrations\\ElasticSearch\\V1\\ElasticSearchIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_MEMCACHED, "Memcached", "__construct",
                                         "DDTrace\\Integrations\\Memcached\\MemcachedIntegration");

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

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_SLIM, "Slim\\App", "__construct",
                                         "DDTrace\\Integrations\\Slim\\SlimIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_WORDPRESS, "Requests", "set_certificate_path",
                                         "DDTrace\\Integrations\\WordPress\\WordPressIntegration");

    DD_SET_UP_DEFERRED_LOADING_BY_METHOD(DDTRACE_INTEGRATION_YII, "yii\\di\\Container", "__construct",
                                         "DDTrace\\Integrations\\Yii\\YiiIntegration");
}

ddtrace_integration* ddtrace_get_integration_from_string(ddtrace_string integration) {
    return zend_hash_str_find_ptr(&_dd_string_to_integration_name_map, integration.ptr, integration.len);
}

static void _dd_add_integration_to_map(char* name, size_t name_len, ddtrace_integration* integration) {
    zend_hash_str_add_ptr(&_dd_string_to_integration_name_map, name, name_len, integration);
    ZEND_ASSERT(strlen(integration->name_ucase) == name_len);
    ZEND_ASSERT(DDTRACE_LONGEST_INTEGRATION_NAME_LEN >= name_len);
}
