<?php

namespace DDTrace\Integrations\MongoDB;

class DatadogSubscriberWithReturnTypes implements \MongoDB\Driver\Monitoring\CommandSubscriber
{
    private static $useDeprecatedMethods = null;

    public function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event): void
    {
        $span = \DDTrace\active_span();
        if ($span) {
            if (is_null(self::$useDeprecatedMethods)) {
                // v1.20+: getServer() is deprecated in favor of getHost() and getPort()
                self::$useDeprecatedMethods = !method_exists($event, 'getHost') || !method_exists($event, 'getPort');
            }

            if (self::$useDeprecatedMethods) {
                $span->meta['out.host'] = $event->getServer()->getHost();
                $span->meta['out.port'] = $event->getServer()->getPort();
            } else {
                $span->meta['out.host'] = $event->getHost();
                $span->meta['out.port'] = $event->getPort();
            }
        }
    }

    public function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void
    {
    }

    public function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent $event): void
    {
    }
}