#pragma once
#include "configuration.h"
#include "integrations.h"
namespace ddtrace {

static inline void _es_add_methods(Integrations &integrations, const ddtrace_string &klass,
                                        std::initializer_list<ddtrace_string> methods, const ddtrace_string &hook) {
    for (auto method : methods) {
        integrations.add(Deferred(klass, method, hook));
    }
}

static inline void _es_add_deferred_integration(Integrations &integrations) {
    if (!ddtrace_config_integration_enabled("elasticsearch"_s)) {
        return;
    }
    auto hook = "DDTrace\\Integrations\\ElasticSearch\\V1\\load"_s;
    _es_add_methods(integrations, "elasticsearch\\client"_s,
                    {"__construct"_s, "count"_s, "delete"_s, "exists"_s, "explain"_s, "get"_s, "index"_s, "scroll"_s,
                     "search"_s, "update"_s},
                    hook);
    _es_add_methods(integrations, "elasticsearch\\serializers\\arraytojsonserializer"_s, {"serialize"_s, "deserialize"_s}, hook);
    _es_add_methods(integrations, "elasticsearch\\serializers\\everythingtojsonserializer"_s, {"serialize"_s, "deserialize"_s}, hook);
    _es_add_methods(integrations, "elasticsearch\\serializers\\smartserializer"_s, {"serialize"_s, "deserialize"_s}, hook);
    _es_add_methods(integrations, "elasticsearch\\namespaces\\indicesnamespace"_s,
                    {"analyze"_s,         "clearcache"_s,  "close"_s,         "create"_s,
                     "delete"_s,          "deletealias"_s, "deletemapping"_s, "deletetemplate"_s,
                     "deletewarmer"_s,    "exists"_s,      "existsalias"_s,   "existstemplate"_s,
                     "existstype"_s,      "flush"_s,       "getalias"_s,      "getaliases"_s,
                     "getfieldmapping"_s, "getmapping"_s,  "getsettings"_s,   "gettemplate"_s,
                     "getwarmer"_s,       "open"_s,        "optimize"_s,      "putalias"_s,
                     "putmapping"_s,      "putsettings"_s, "puttemplate"_s,   "putwarmer"_s,
                     "recovery"_s,        "refresh"_s,     "segments"_s,      "snapshotindex"_s,
                     "stats"_s,           "status"_s,      "updatealiases"_s, "validatequery"_s},
                    hook);
    _es_add_methods(integrations, "elasticsearch\\namespaces\\catnamespace"_s,
                    {"aliases"_s, "allocation"_s, "count"_s, "fielddata"_s, "health"_s, "help"_s, "indices"_s,
                     "master"_s, "nodes"_s, "pendingtasks"_s, "recovery"_s, "shards"_s, "threadpool"_s},
                    hook);
    _es_add_methods(integrations, "elasticsearch\\namespaces\\snapshotnamespace"_s,
                    {"create"_s, "createrepository"_s, "delete"_s, "deleterepository"_s, "get"_s, "getrepository"_s,
                     "restore"_s, "status"_s},
                    hook);
    _es_add_methods(integrations, "elasticsearch\\namespaces\\clusternamespace"_s,
                    {"getsettings"_s, "health"_s, "pendingtasks"_s, "putsettings"_s, "reroute"_s, "state"_s, "stats"_s},
                    hook);
    _es_add_methods(integrations, "elasticsearch\\namespaces\\nodesnamespace"_s,
                    {"hotthreads"_s, "info"_s, "shutdown"_s, "stats"_s}, hook);
    integrations.add(Deferred("elasticsearch\\endpoints\\abstractendpoint"_s, "performrequest"_s, hook));
}

}  // namespace ddtrace
