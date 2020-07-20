#ifndef DD_INTEGRATIONS_ELASTICSEARCH_H
#define DD_INTEGRATIONS_ELASTICSEARCH_H
#include "configuration.h"
#include "integrations.h"

#define _DD_AL_ES(class, method) \
    DDTRACE_DEFERRED_INTEGRATION_LOADER(class, method, "DDTrace\\Integrations\\ElasticSearch\\V1\\load")

static inline void _dd_es_initialize_deferred_integration(TSRMLS_D) {
    if (!ddtrace_config_integration_enabled_ex(DDTRACE_INTEGRATION_ELASTICSEARCH TSRMLS_CC)) {
        return;
    }

    _DD_AL_ES("elasticsearch\\client", "__construct");
    _DD_AL_ES("elasticsearch\\client", "count");
    _DD_AL_ES("elasticsearch\\client", "delete");
    _DD_AL_ES("elasticsearch\\client", "exists");
    _DD_AL_ES("elasticsearch\\client", "explain");
    _DD_AL_ES("elasticsearch\\client", "get");
    _DD_AL_ES("elasticsearch\\client", "index");
    _DD_AL_ES("elasticsearch\\client", "scroll");
    _DD_AL_ES("elasticsearch\\client", "search");
    _DD_AL_ES("elasticsearch\\client", "update");
    _DD_AL_ES("elasticsearch\\serializers\\arraytojsonserializer", "serialize");
    _DD_AL_ES("elasticsearch\\serializers\\arraytojsonserializer", "deserialize");
    _DD_AL_ES("elasticsearch\\serializers\\everythingtojsonserializer", "serialize");
    _DD_AL_ES("elasticsearch\\serializers\\everythingtojsonserializer", "deserialize");
    _DD_AL_ES("elasticsearch\\serializers\\smartserializer", "serialize");
    _DD_AL_ES("elasticsearch\\serializers\\smartserializer", "deserialize");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "analyze");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "clearcache");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "close");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "create");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "delete");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletealias");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletemapping");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletetemplate");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletewarmer");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "exists");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existsalias");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existstemplate");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existstype");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "flush");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getalias");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getaliases");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getfieldmapping");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getmapping");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getsettings");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "gettemplate");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getwarmer");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "open");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "optimize");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putalias");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putmapping");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putsettings");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "puttemplate");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putwarmer");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "recovery");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "refresh");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "segments");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "snapshotindex");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "stats");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "status");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "updatealiases");
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "validatequery");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "aliases");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "allocation");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "count");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "fielddata");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "health");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "help");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "indices");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "master");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "nodes");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "pendingtasks");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "recovery");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "shards");
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "threadpool");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "create");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "createrepository");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "delete");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "deleterepository");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "get");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "getrepository");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "restore");
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "status");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "getsettings");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "health");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "pendingtasks");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "putsettings");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "reroute");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "state");
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "stats");
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "hotthreads");
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "info");
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "shutdown");
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "stats");
    _DD_AL_ES("elasticsearch\\endpoints\\abstractendpoint", "performrequest");
}

#endif
