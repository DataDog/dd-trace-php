#include "integrations.h"

#include "ddtrace_string.h"
#include "elasticsearch.h"
#include "test_integration.h"

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
                          DDTRACE_STRING_LITERAL(NULL), DDTRACE_DISPATCH_POSTHOOK TSRMLS_CC)

/* Due to negative lookup caching, we need to have a list of all things we
 * might instrument so that if a call is made to something we want to later
 * instrument but is not currently instrumented, that we don't cache this.
 *
 * We should improve how this list is made in the future instead of hard-
 * coding known integrations (and for now only the problematic ones).
 */
static void dd_register_known_calls(TSRMLS_D) {
    DDTRACE_KNOWN_INTEGRATION("wpdb", "query");
    DDTRACE_KNOWN_INTEGRATION("illuminate\\events\\dispatcher", "fire");
}

#if PHP_VERSION_ID >= 70000

void ddtrace_integrations_rinit(TSRMLS_D) {
    dd_register_known_calls();

    _dd_es_initialize_deferred_integration(TSRMLS_C);
    _dd_load_test_integrations(TSRMLS_C);
}

ddtrace_integration* ddtrace_get_integration_from_string(ddtrace_string integration) {
    return zend_hash_str_find_ptr(&_dd_string_to_integration_name_map, integration.ptr, integration.len);
}

static void _dd_add_integration_to_map(char* name, size_t name_len, ddtrace_integration* integration) {
    zend_hash_str_add_ptr(&_dd_string_to_integration_name_map, name, name_len, integration);
    ZEND_ASSERT(strlen(integration->name_ucase) == name_len);
    ZEND_ASSERT(DDTRACE_LONGEST_INTEGRATION_NAME_LEN >= name_len);
}
#else
void ddtrace_integrations_rinit(TSRMLS_D) {
    /* In PHP 5.6 currently adding deferred integrations seem to trigger increase in heap
     * size - even though the memory usage is below the limit. We still can trigger memory
     * allocation error to be issued
     */
    _dd_load_test_integrations(TSRMLS_C);

    dd_register_known_calls(TSRMLS_C);
}

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration) {
    ddtrace_integration **tmp;
    if (zend_hash_find(&_dd_string_to_integration_name_map, integration.ptr, integration.len + 1, (void **)&tmp) ==
        SUCCESS) {
        return *tmp;
    }
    return NULL;
}

static void _dd_add_integration_to_map(char *name, size_t name_len, ddtrace_integration *integration) {
    zend_hash_add(&_dd_string_to_integration_name_map, name, name_len + 1, (void **)&integration, sizeof(integration),
                  NULL);
}
#endif
