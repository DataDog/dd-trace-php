#ifndef DD_INTEGRATIONS_INTEGRATIONS_H
#define DD_INTEGRATIONS_INTEGRATIONS_H
#include <php.h>

#include "ddtrace_string.h"
#include "dispatch.h"

#define DDTRACE_LONGEST_INTEGRATION_NAME_LEN 13  // "zendframework" FTW!

typedef enum {
    DDTRACE_INTEGRATION_CAKEPHP,
    DDTRACE_INTEGRATION_CODEIGNITER,
    DDTRACE_INTEGRATION_CURL,
    DDTRACE_INTEGRATION_ELASTICSEARCH,
    DDTRACE_INTEGRATION_ELOQUENT,
    DDTRACE_INTEGRATION_GUZZLE,
    DDTRACE_INTEGRATION_LARAVEL,
    DDTRACE_INTEGRATION_LUMEN,
    DDTRACE_INTEGRATION_MEMCACHED,
    DDTRACE_INTEGRATION_MONGO,
    DDTRACE_INTEGRATION_MYSQLI,
    DDTRACE_INTEGRATION_PDO,
    DDTRACE_INTEGRATION_PHPREDIS,
    DDTRACE_INTEGRATION_PREDIS,
    DDTRACE_INTEGRATION_SLIM,
    DDTRACE_INTEGRATION_SYMFONY,
    DDTRACE_INTEGRATION_WEB,
    DDTRACE_INTEGRATION_WORDPRESS,
    DDTRACE_INTEGRATION_YII,
    DDTRACE_INTEGRATION_ZENDFRAMEWORK,
} ddtrace_integration_name;

struct ddtrace_integration {
    ddtrace_integration_name name;
    char *name_ucase;
    char *name_lcase;
    size_t name_len;
};
typedef struct ddtrace_integration ddtrace_integration;

extern ddtrace_integration ddtrace_integrations[];
extern size_t ddtrace_integrations_len;

void ddtrace_integrations_minit(void);
void ddtrace_integrations_mshutdown(void);
void ddtrace_integrations_rinit(TSRMLS_D);

ddtrace_integration *ddtrace_get_integration_from_string(ddtrace_string integration);

#endif  // DD_INTEGRATIONS_INTEGRATIONS_H
