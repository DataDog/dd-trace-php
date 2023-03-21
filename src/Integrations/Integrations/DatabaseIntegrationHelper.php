<?php

namespace DDTrace\Integrations;

use DDTrace\HookData;

class DatabaseIntegrationHelper
{
    public static function injectDatabaseIntegrationData(HookData $hook, $backend, $argNum = 0)
    {
        $allowedBackends = [
            "sqlsrv" => true,
            "mysql" => true,
            "pgsql" => true,
            "dblib" => true,
            "odbc" => true,
        ];

        $propagationMode = dd_trace_env_config("DD_DBM_PROPAGATION_MODE");
        if ($propagationMode != \DDTrace\DBM_PROPAGATION_DISABLED && isset($allowedBackends[$backend])) {
            $fullPropagationBackends = [
                "mysql" => true,
                "pgsql" => true,
            ];

            if ($propagationMode == \DDTrace\DBM_PROPAGATION_FULL && !is($fullPropagationBackends[$backend])) {
                $propagationMode = \DDTrace\DBM_PROPAGATION_SERVICE;
            }

            $query = self::propagateViaSqlComments($hook->args[$argNum], $hook->span()->service, $propagationMode);
            $hook->args[$argNum] = $query;
            $hook->overrideArguments($hook->args);
            $hook->span()->meta["_dd.dbm_trace_injected"] = "true";
        }
    }

    public static function propagateViaSqlComments($query, $databaseService, $mode = \DDTrace\DBM_PROPAGATION_FULL)
    {
        $rootSpan = \DDTrace\root_span();

        // Note: the order of the tags is relevant, they must be passed ordered alphabetically
        $tags = [];

        if ($databaseService != "") {
            $tags["dddbs"] = $databaseService;
        }

        $env = $rootSpan->meta["env"] ?? "";
        if ($env == "") {
            $env = ini_get("datadog.env");
        }
        if ($env != "") {
            $tags["dde"] = $env;
        }

        $service = $rootSpan->service ?? "";
        if ($service == "") {
            $service = ddtrace_config_app_name();
        }
        if ($service != "") {
            $tags["ddps"] = $service;
        }

        $version = $rootSpan->meta["version"] ?? "";
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
