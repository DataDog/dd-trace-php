#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>
#include <stdbool.h>

#include "../ddtrace_string.h"

#define DD_TRACE_INTEGRATION_NOT_LOADED 0
#define DD_TRACE_INTEGRATION_LOADED 1
#define DD_TRACE_INTEGRATION_NOT_AVAILABLE 2

#define DDTRACE_LONGEST_INTEGRATION_NAME_LEN 13  // "zendframework" FTW!

#define DD_INTEGRATIONS                          \
    INTEGRATION(AMQP, "amqp")                   \
    INTEGRATION(CAKEPHP, "cakephp")             \
    INTEGRATION(CODEIGNITER, "codeigniter")     \
    INTEGRATION(EXEC, "exec")                   \
    INTEGRATION(FILESYSTEM, "filesystem")       \
    INTEGRATION(CURL, "curl")                   \
    INTEGRATION(DRUPAL, "drupal")               \
    INTEGRATION(ELASTICSEARCH, "elasticsearch") \
    INTEGRATION(ELOQUENT, "eloquent")           \
    INTEGRATION(FRANKENPHP, "frankenphp")       \
    INTEGRATION(GUZZLE, "guzzle")               \
    INTEGRATION(LAMINAS, "laminas")             \
    INTEGRATION(LARAVEL, "laravel")             \
    INTEGRATION(LARAVELQUEUE, "laravelqueue")   \
    INTEGRATION(LOGS, "logs", "false", DD_LOGS_INJECTION) \
    INTEGRATION(LUMEN, "lumen")                 \
    INTEGRATION(MAGENTO, "magento")             \
    INTEGRATION(MEMCACHE, "memcache")           \
    INTEGRATION(MEMCACHED, "memcached")         \
    INTEGRATION(MONGO, "mongo")                 \
    INTEGRATION(MONGODB, "mongodb")             \
    INTEGRATION(MYSQLI, "mysqli")               \
    INTEGRATION(NETTE, "nette")                 \
    INTEGRATION(OPENAI, "openai")               \
    INTEGRATION(PCNTL, "pcntl")                 \
    INTEGRATION(PDO, "pdo")                     \
    INTEGRATION(PHPREDIS, "phpredis")           \
    INTEGRATION(PREDIS, "predis")               \
    INTEGRATION(PSR18, "psr18")                 \
    INTEGRATION(ROADRUNNER, "roadrunner")       \
    INTEGRATION(SQLSRV, "sqlsrv")               \
    INTEGRATION(SLIM, "slim")                   \
    INTEGRATION(SWOOLE, "swoole")               \
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
    bool (*is_enabled)(void);
    bool (*is_analytics_enabled)(void);
    double (*get_sample_rate)(void);
    void *aux[10]; // Needs to be the max number of deferred loading hooks for any integration plus one NULL sentinel
};
typedef struct ddtrace_integration ddtrace_integration;

extern ddtrace_integration ddtrace_integrations[];
extern size_t ddtrace_integrations_len;

void ddtrace_integrations_minit(void);
void ddtrace_integrations_mshutdown(void);

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration);

#endif  // DD_INTEGRATIONS_INTEGRATIONS_H
