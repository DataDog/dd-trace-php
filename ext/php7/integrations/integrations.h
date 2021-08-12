#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>
#include <stdbool.h>

#include "ext/php7/ddtrace_string.h"
#include "ext/php7/dispatch.h"

#define DDTRACE_LONGEST_INTEGRATION_NAME_LEN 13  // "zendframework" FTW!

#define DD_INTEGRATIONS                         \
    INTEGRATION(CAKEPHP, "cakephp")             \
    INTEGRATION(CODEIGNITER, "codeigniter")     \
    INTEGRATION(CURL, "curl")                   \
    INTEGRATION(ELASTICSEARCH, "elasticsearch") \
    INTEGRATION(ELOQUENT, "eloquent")           \
    INTEGRATION(GUZZLE, "guzzle")               \
    INTEGRATION(LARAVEL, "laravel")             \
    INTEGRATION(LUMEN, "lumen")                 \
    INTEGRATION(MEMCACHED, "memcached")         \
    INTEGRATION(MONGO, "mongo")                 \
    INTEGRATION(MYSQLI, "mysqli")               \
    INTEGRATION(NETTE, "nette")                 \
    INTEGRATION(PDO, "pdo")                     \
    INTEGRATION(PHPREDIS, "phpredis")           \
    INTEGRATION(PREDIS, "predis")               \
    INTEGRATION(SLIM, "slim")                   \
    INTEGRATION(SYMFONY, "symfony")             \
    INTEGRATION(WEB, "web")                     \
    INTEGRATION(WORDPRESS, "wordpress")         \
    INTEGRATION(YII, "yii")                     \
    INTEGRATION(ZENDFRAMEWORK, "zendframework")

#define INTEGRATION(id, ...) DDTRACE_INTEGRATION_##id,
typedef enum { DD_INTEGRATIONS } ddtrace_integration_name;
#undef INTEGRATION

struct ddtrace_integration {
    ddtrace_integration_name name;
    char *name_ucase;
    char *name_lcase;
    size_t name_len;
    bool (*is_enabled)();
    bool (*is_analytics_enabled)();
    double (*get_sample_rate)();
};
typedef struct ddtrace_integration ddtrace_integration;

extern ddtrace_integration ddtrace_integrations[];
extern size_t ddtrace_integrations_len;

void ddtrace_integrations_minit(void);
void ddtrace_integrations_mshutdown(void);
void ddtrace_integrations_rinit(void);

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration);

#endif  // DD_INTEGRATIONS_INTEGRATIONS_H
