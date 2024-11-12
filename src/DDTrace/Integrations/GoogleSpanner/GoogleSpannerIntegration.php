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

    public function init(): int
    {
        $integration = $this;

        \DDTrace\trace_method('Google\Cloud\Spanner\SpannerClient', 'instance', function (SpanData $span, $args) use ($integration) {
            $instanceName =$args[0];
            $span->meta[Tag::DB_INSTANCE] = $instanceName;
            $integration->setDefaultAttributes($span, 'google_spanner.instance', $args[0]);
            ObjectKVStore::put($this, GoogleSpannerIntegration::KEY_INSTANCE_NAME, $instanceName);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Instance', 'database', function (SpanData $span, $args) use ($integration) {
            $dbName =$args[0];
            $span->meta[Tag::DB_NAME] = $dbName;
            $integration->setDefaultAttributes($span, 'google_spanner.database', $args[0]);
            ObjectKVStore::put($this, GoogleSpannerIntegration::KEY_DATABASE_NAME, $dbName);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'execute', function (SpanData $span, $args) use ($integration) {
            $span->meta[Tag::DB_NAME] = $this->name();
            $integration->setDefaultAttributes($span, 'google_spanner.execute', $args[0]);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'runTransaction', function (SpanData $span, $args) use ($integration) {
            $integration->setDefaultAttributes($span, 'google_spanner.run_transaction', 'transaction');
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Database', 'transaction', function (SpanData $span, $args) use ($integration) {
            $integration->setDefaultAttributes($span, 'google_spanner.transaction', 'transaction');
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'commit', function (SpanData $span) use ($integration) {
            $integration->setDefaultAttributes($span, 'google_spanner.commit', "commit");
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'executeUpdate', function (SpanData $span, $args) use ($integration) {
            $integration->setDefaultAttributes($span, 'google_spanner.execute_update', $args[0]);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('Google\Cloud\Spanner\Transaction', 'executeUpdateBatch', function (SpanData $span) use ($integration) {
            $integration->setDefaultAttributes($span, 'google_spanner.execute_update_batch', 'execute_update_batch');
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        return Integration::LOADED;
    }

    public function setDefaultAttributes(SpanData $span, $name, $resource)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, GoogleSpannerIntegration::NAME);
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = GoogleSpannerIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = GoogleSpannerIntegration::SYSTEM;
    }

}
