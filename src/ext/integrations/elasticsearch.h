#ifndef DD_INTEGRATIONS_ELASTICSEARCH_H
#define DD_INTEGRATIONS_ELASTICSEARCH_H
#include "configuration.h"
#include "integrations.h"

#define ES_INTEGRATION_POOL_ID 2

#define _DD_AL_ES(class, method, id)                                                                     \
    DDTRACE_DEFERRED_INTEGRATION_LOADER(class, method, "DDTrace\\Integrations\\ElasticSearch\\V1\\load", \
                                        ES_INTEGRATION_POOL_ID, id)

static inline void _dd_es_initialize_deferred_integration(TSRMLS_D) {
    ddtrace_string elasticsearch = DDTRACE_STRING_LITERAL("elasticsearch");
    if (!ddtrace_config_integration_enabled(elasticsearch TSRMLS_CC)) {
        return;
    }
    if (!ddtrace_initialize_new_dispatch_pool(ES_INTEGRATION_POOL_ID, 85)) {
        return;
    }
    _DD_AL_ES("elasticsearch\\client", "__construct", 0);
    _DD_AL_ES("elasticsearch\\client", "count", 1);
    _DD_AL_ES("elasticsearch\\client", "delete", 2);
    _DD_AL_ES("elasticsearch\\client", "exists", 3);
    _DD_AL_ES("elasticsearch\\client", "explain", 4);
    _DD_AL_ES("elasticsearch\\client", "get", 5);
    _DD_AL_ES("elasticsearch\\client", "index", 6);
    _DD_AL_ES("elasticsearch\\client", "scroll", 7);
    _DD_AL_ES("elasticsearch\\client", "search", 8);
    _DD_AL_ES("elasticsearch\\client", "update", 9);
    _DD_AL_ES("elasticsearch\\serializers\\arraytojsonserializer", "serialize", 10);
    _DD_AL_ES("elasticsearch\\serializers\\arraytojsonserializer", "deserialize", 11);
    _DD_AL_ES("elasticsearch\\serializers\\everythingtojsonserializer", "serialize", 12);
    _DD_AL_ES("elasticsearch\\serializers\\everythingtojsonserializer", "deserialize", 13);
    _DD_AL_ES("elasticsearch\\serializers\\smartserializer", "serialize", 14);
    _DD_AL_ES("elasticsearch\\serializers\\smartserializer", "deserialize", 15);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "analyze", 16);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "clearcache", 17);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "close", 18);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "create", 19);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "delete", 20);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletealias", 21);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletemapping", 22);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletetemplate", 23);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "deletewarmer", 24);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "exists", 25);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existsalias", 26);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existstemplate", 27);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "existstype", 28);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "flush", 29);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getalias", 30);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getaliases", 31);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getfieldmapping", 32);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getmapping", 33);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getsettings", 34);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "gettemplate", 35);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "getwarmer", 36);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "open", 37);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "optimize", 38);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putalias", 39);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putmapping", 40);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putsettings", 41);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "puttemplate", 42);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "putwarmer", 43);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "recovery", 44);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "refresh", 45);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "segments", 46);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "snapshotindex", 47);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "stats", 48);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "status", 49);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "updatealiases", 50);
    _DD_AL_ES("elasticsearch\\namespaces\\indicesnamespace", "validatequery", 51);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "aliases", 52);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "allocation", 53);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "count", 54);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "fielddata", 55);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "health", 56);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "help", 57);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "indices", 58);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "master", 59);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "nodes", 60);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "pendingtasks", 61);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "recovery", 62);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "shards", 63);
    _DD_AL_ES("elasticsearch\\namespaces\\catnamespace", "threadpool", 64);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "create", 65);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "createrepository", 66);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "delete", 67);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "deleterepository", 68);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "get", 69);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "getrepository", 70);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "restore", 71);
    _DD_AL_ES("elasticsearch\\namespaces\\snapshotnamespace", "status", 72);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "getsettings", 73);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "health", 74);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "pendingtasks", 75);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "putsettings", 76);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "reroute", 77);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "state", 78);
    _DD_AL_ES("elasticsearch\\namespaces\\clusternamespace", "stats", 79);
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "hotthreads", 80);
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "info", 81);
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "shutdown", 82);
    _DD_AL_ES("elasticsearch\\namespaces\\nodesnamespace", "stats", 83);
    _DD_AL_ES("elasticsearch\\endpoints\\abstractendpoint", "performrequest", 84);
}

#endif
