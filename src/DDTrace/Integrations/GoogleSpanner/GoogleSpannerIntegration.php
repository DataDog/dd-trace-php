<?php

namespace DDTrace\Integrations\GoogleSpanner;

use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class GoogleSpannerIntegration extends Integration
{
    const NAME = 'google_spanner';
    const SYSTEM = 'spanner';
    const KEY_DATABASE_NAME = 'database_name';
    const KEY_INSTANCE_NAME = 'spanner_instance';

    public static function init(): int
    {
        \DDTrace\trace_method('Google\Cloud\Spanner\SpannerClient', 'instance', function (SpanData $span, $args) {
            $instanceName = $args[0];
            $span->meta[Tag::DB_INSTANCE] = $instanceName;
            GoogleSpannerIntegration::setDefaultAttributes($span, 'google_spanner.instance', $args[0]);
            ObjectKVStore::put($this, GoogleSpannerIntegration::KEY_INSTANCE_NAME, $instanceName);
            GoogleSpannerIntegration::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Instance', 'database', function (SpanData $span, $args) {
            $dbName =$args[0];
            $span->meta[Tag::DB_NAME] = $dbName;
            GoogleSpannerIntegration::setDefaultAttributes($span, 'google_spanner.database', $args[0]);
            ObjectKVStore::put($this, GoogleSpannerIntegration::KEY_DATABASE_NAME, $dbName);
            GoogleSpannerIntegration::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'execute', function (SpanData $span, $args) {
            $span->meta[Tag::DB_NAME] = $this->name();
            GoogleSpannerIntegration::setDefaultAttributes($span, 'google_spanner.execute', $args[0]);
            GoogleSpannerIntegration::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'runTransaction', static function (SpanData $span, $args) {
            self::setDefaultAttributes($span, 'google_spanner.run_transaction', 'transaction');
            self::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'transaction', static function (SpanData $span, $args) {
            self::setDefaultAttributes($span, 'google_spanner.transaction', 'transaction');
            self::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'commit', static function (SpanData $span) {
            self::setDefaultAttributes($span, 'google_spanner.commit', "commit");
            self::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'executeUpdate', static function (SpanData $span, $args) {
            self::setDefaultAttributes($span, 'google_spanner.execute_update', $args[0]);
            self::addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'executeUpdateBatch', static function (SpanData $span) {
            self::setDefaultAttributes($span, 'google_spanner.execute_update_batch', 'execute_update_batch');
            self::addTraceAnalyticsIfEnabled($span);
        });

        return Integration::LOADED;
    }

    public static function setDefaultAttributes(SpanData $span, $name, $resource)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, self::NAME);
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::SYSTEM;
    }

}
