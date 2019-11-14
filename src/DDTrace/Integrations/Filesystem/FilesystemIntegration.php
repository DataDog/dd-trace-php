<?php

namespace DDTrace\Integrations\Filesystem;

use DDTrace\Integrations\Integration;
use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\GlobalTracer;

/**
 * Integration for native PHP filesystem functions.
 */
class FilesystemIntegration extends Integration
{
    const NAME = 'filesystem';

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Loads the integration.
     */
    public static function load()
    {
        dd_trace('file_get_contents', function ($file) {
            $tracer = GlobalTracer::get();

            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                FilesystemIntegration::getInstance(),
                'filesystem.file_get_contents'
            );
            $span = $scope->getSpan();

            $span->setTag(Tag::SPAN_TYPE, Type::FILESYSTEM);
            $span->setTag(Tag::SERVICE_NAME, 'filesystem');
            $span->setTag(Tag::RESOURCE_NAME, $file);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        dd_trace('file_put_contents', function ($file) {
            $tracer = GlobalTracer::get();

            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                FilesystemIntegration::getInstance(),
                'filesystem.file_put_contents'
            );
            $span = $scope->getSpan();

            $span->setTag(Tag::SPAN_TYPE, Type::FILESYSTEM);
            $span->setTag(Tag::SERVICE_NAME, 'filesystem');
            $span->setTag(Tag::RESOURCE_NAME, $file);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        return Integration::LOADED;
    }
}
