<?php

namespace DDTrace\Integrations;

use DDTrace\HookData;
use DDTrace\Tag;

class DatabaseIntegrationHelper
{
    const PEER_SERVICE_SOURCES = [
        Tag::DB_INSTANCE,
        Tag::DB_NAME,  // Deprecated, it should be db.instance
        Tag::MONGODB_DATABASE,

        // For cases like RedisCluster when the cluster name is not defined, we use the first configured host as an
        // easy to map proxy for the cluster name. This is temporary — hence the _dd prefix — until we agree on a
        // strategy across all tracers.
        '_dd.cluster.name',
        '_dd.first.configured.host',

        Tag::TARGET_HOST,
    ];

    public static function injectDatabaseIntegrationData(HookData $hook, $backend, $argNum = 0, $preventFullMode = false)
    {
        $allowedBackends = [
            "sqlsrv" => true,
            "mysql" => true,
            "pgsql" => true,
            "mssql" => true, // part of dblib
            "dblib" => true,
            "odbc" => true,
        ];

        $propagationMode = dd_trace_env_config("DD_DBM_PROPAGATION_MODE");
        if ($propagationMode != \DDTrace\DBM_PROPAGATION_DISABLED && isset($allowedBackends[$backend])) {
            $fullPropagationBackends = [
                "mysql" => true,
                "pgsql" => true,
            ];

            if ($propagationMode == \DDTrace\DBM_PROPAGATION_FULL && (!isset($fullPropagationBackends[$backend]) || $preventFullMode)) {
                $propagationMode = \DDTrace\DBM_PROPAGATION_SERVICE;
            }

            $span = $hook->span();
            $targetHost = $span->meta[Tag::TARGET_HOST] ?? '';
            $dbName = $span->meta[Tag::DB_NAME] ?? $span->meta[Tag::DB_INSTANCE] ?? '';
            $peerService = $span->meta['peer.service'] ?? '';

            $query = self::propagateViaSqlComments(
                $hook->args[$argNum],
                $span->service,
                $propagationMode,
                $targetHost,
                $dbName,
                $peerService
            );
            $hook->args[$argNum] = $query;
            $hook->overrideArguments($hook->args);
            if ($propagationMode == \DDTrace\DBM_PROPAGATION_FULL) {
                $hook->span()->meta["_dd.dbm_trace_injected"] = "true";
            }
        }
    }

    public static function propagateViaSqlComments(
        $query,
        $databaseService,
        $mode = \DDTrace\DBM_PROPAGATION_FULL,
        $targetHost = '',
        $dbName = '',
        $peerService = ''
    ) {
        $rootSpan = \DDTrace\root_span();

        // Note: the order of the tags is relevant, they must be passed ordered alphabetically
        $tags = [];

        if ($dbName != "") {
            $tags["dddb"] = $dbName;
        }

        if ($databaseService != "") {
            $tags["dddbs"] = $databaseService;
        }

        $env = $rootSpan->meta["env"] ?? $rootSpan->env ?? "";
        if ($env == "") {
            $env = ini_get("datadog.env");
        }
        if ($env != "") {
            $tags["dde"] = $env;
        }

        if ($targetHost != "") {
            $tags["ddh"] = $targetHost; // peer hostname
        }

        if ($peerService != "") {
            $tags["ddprs"] = $peerService;
        }

        $service = $rootSpan->service ?? "";
        if ($service == "") {
            $service = ddtrace_config_app_name();
        }
        if ($service != "") {
            $mapping = dd_trace_env_config('DD_SERVICE_MAPPING');
            if (isset($mapping[$service])) {
                $service = $mapping[$service];
            }
            $tags["ddps"] = $service;
        }

        $version = $rootSpan->meta["version"] ?? $rootSpan->version ?? "";
        if ($version == "") {
            $version = ini_get("datadog.version");
        }
        if ($version != "") {
            $tags["ddpv"] = $version;
        }

        if ($mode == \DDTrace\DBM_PROPAGATION_FULL) {
            if ($headers = \DDTrace\generate_distributed_tracing_headers(["tracecontext"])) {
                $tags["traceparent"] = $headers["traceparent"];
            }
        }

        return self::injectSqlComment($query, $tags);
    }

    public static function injectSqlComment($query, array $tags)
    {
        if (!$tags) {
            return $query;
        }

        $escaped = [];
        foreach ($tags as $tag => $val) {
            // Note: we also urlencode single quotes and backslashes, hence no particular handling needed for these
            $escaped[] = rawurlencode($tag) . "='" . rawurlencode($val) . "'";
        }
        // "/" . "*" to prevent comment stripping from removing this...
        $comment = "/" . "*" . implode(',', $escaped) . "*/";

        if ($query == "") {
            return $comment;
        }
        return "$comment $query";
    }
}
